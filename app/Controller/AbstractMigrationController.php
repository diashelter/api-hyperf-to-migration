<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Swagger\Annotation\HyperfServer;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Ramsey\Uuid\Uuid;

use function PHPUnit\Framework\stringEndsWith;

#[HyperfServer('http')]
abstract class AbstractMigrationController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

    #[Inject]
    protected ParallelInsertService $insertService;

    #[Inject]
    protected IdMappingService $idMappingService;

    #[Inject]
    protected MigrationBatchService $batchService;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    abstract protected function getTable(): string;

    abstract protected function getEntity(): string;

    abstract protected function getMaxBatchSize(): int;

    protected function getConnection(): string
    {
        return 'default';
    }

    protected function getContractId(): string
    {
        return $this->request->getAttribute('contract_id', $this->request->header('X-Contract-Id', ''));
    }

    /**
     * Regras de validação por campo para cada registro do batch.
     * Override em cada controller com as regras específicas da entidade.
     * Retornar array vazio desativa a validação (compatibilidade retroativa).
     *
     * Exemplo:
     * return [
     *   'name'  => 'required|string|max:255',
     *   'email' => 'required|email',
     * ];
     */
    protected function validationRules(): array
    {
        return [];
    }

    /**
     * Separa o batch em registros válidos e inválidos.
     * Registros inválidos são reportados em errors[] sem travar o restante.
     *
     * Retorna [$validRecords, $validationErrors].
     */
    protected function filterValidRecords(array $batch): array
    {
        $rules = $this->validationRules();

        if (empty($rules)) {
            return [$batch, []];
        }

        $validRecords = [];
        $validationErrors = [];

        foreach ($batch as $index => $record) {
            $validator = $this->validatorFactory->make($record, $rules);

            if ($validator->fails()) {
                $validationErrors[] = [
                    'index'             => $index,
                    'legacy_id'         => $record['legacy_id'] ?? null,
                    'validation_errors' => $validator->errors()->toArray(),
                ];
            } else {
                $validRecords[] = $record;
            }
        }

        return [$validRecords, $validationErrors];
    }

    protected function syncMigrate(): array
    {
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($batch) > $this->getMaxBatchSize()) {
            return [
                'error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}",
                'code' => 422,
            ];
        }

        [$batch, $validationErrors] = $this->filterValidRecords($batch);

        if (empty($batch)) {
            return [
                'inserted'    => 0,
                'failed'      => \count($validationErrors),
                'errors'      => $validationErrors,
                'id_mappings' => [],
            ];
        }

        $contractId = $this->getContractId();
        $now = date('Y-m-d H:i:s');
        $idMappings = [];

        foreach ($batch as &$record) {
            foreach (array_keys($record) as $field) {
                if (
                    $field === 'password' ||
                    $field === 'contractor_type' ||
                    stringEndsWith($field, '_id') ||
                    !is_string($record[$field])
                ) {
                    continue;
                }
                if ($field === 'email') {
                    $record[$field] = strtolower($record[$field] ?? '');
                    continue;
                }
                $record[$field] = strtoupper($record[$field] ?? '');
            }
            $legacyId = $record['legacy_id'] ?? null;
            unset($record['legacy_id']);

            if (empty($record['id'])) {
                $record['id'] = $this->generateId();
            }

            if (!empty($record['password'])) {
                $record['password'] = password_hash($record['password'], PASSWORD_BCRYPT);
            }

            $record['created_at'] = $record['created_at'] ?? $now;
            $record['updated_at'] = $record['updated_at'] ?? $now;

            $record = $this->resolveForeignKeys($record, $contractId);

            if ($legacyId !== null) {
                $idMappings[$legacyId] = $record['id'];
            }
        }

        $results = $this->insertService->insertSync($this->getTable(), $batch, $this->getConnection());

        if (! empty($idMappings)) {
            $this->idMappingService->storeBatch($this->getEntity(), $idMappings, $contractId);
        }

        $results['id_mappings'] = $idMappings;
        $results['errors'] = array_merge($validationErrors, $results['errors']);
        $results['failed'] += \count($validationErrors);

        return $results;
    }

    protected function asyncMigrate(): array
    {
        $batch = $this->request->input('batch', []);
        $contractId = $this->getContractId();

        [$batch, $validationErrors] = $this->filterValidRecords($batch);

        $migrationBatch = $this->batchService->create(
            $this->getEntity(),
            \count($batch),
            $contractId
        );

        $batchId = (string) $migrationBatch->getAttribute('id');

        $this->batchService->markProcessing($batchId);

        $now = date('Y-m-d H:i:s');
        $idMappings = [];

        foreach ($batch as &$record) {
            $legacyId = $record['legacy_id'] ?? null;
            unset($record['legacy_id']);

            if (empty($record['id'])) {
                $record['id'] = $this->generateId();
            }

            $record['created_at'] = $record['created_at'] ?? $now;
            $record['updated_at'] = $record['updated_at'] ?? $now;

            $record = $this->resolveForeignKeys($record, $contractId);

            if ($legacyId !== null) {
                $idMappings[$legacyId] = $record['id'];
            }
        }

        if (! empty($batch)) {
            $results = $this->insertService->insertBatch($this->getTable(), $batch, 0, 0, $this->getConnection());
        } else {
            $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];
        }

        if (! empty($idMappings)) {
            $this->idMappingService->storeBatch($this->getEntity(), $idMappings, $contractId, $batchId);
        }

        $allErrors = array_merge($validationErrors, $results['errors']);
        $totalFailed = $results['failed'] + \count($validationErrors);

        $this->batchService->markCompleted(
            $batchId,
            $results['inserted'],
            $totalFailed,
            $allErrors ?: null
        );

        return [
            'migration_batch_id' => $batchId,
            'entity'             => $this->getEntity(),
            'total_received'     => \count($batch) + \count($validationErrors),
            'status'             => $totalFailed > 0 ? 'completed_with_errors' : 'completed',
            'inserted'           => $results['inserted'],
            'failed'             => $totalFailed,
            'errors'             => $allErrors,
            'id_mappings'        => $idMappings,
            'status_url'         => "/api/v1/migration/status/{$batchId}",
        ];
    }

    /**
     * Gera o UUID para novos registros. Override em subclasses que precisam
     * de UUID ordenado (ex: import_records usa uuid7 para performance de índice).
     */
    protected function generateId(): string
    {
        return Uuid::uuid4()->toString();
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        return $record;
    }

    /**
     * Resolve contract_id a partir do legacy_contract_id do payload ou,
     * como fallback, resolve o próprio $contractId (X-Contract-Id legado)
     * via migration_id_mappings para obter o UUID real do contrato.
     */
    protected function resolveContractIdFK(array $record, string $contractId): array
    {
        if (! empty($record['legacy_contract_id'])) {
            $record['contract_id'] = $this->idMappingService->resolve('contracts', $record['legacy_contract_id'], $contractId) ?? $record['contract_id'] ?? null;
            unset($record['legacy_contract_id']);
        }

        if (empty($record['contract_id'])) {
            $record['contract_id'] = $this->idMappingService->resolve('contracts', $contractId, $contractId);
        }

        return $record;
    }
}
