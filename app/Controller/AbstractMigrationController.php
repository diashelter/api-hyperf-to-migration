<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\IdMappingService;
use App\Service\MigrationAuditService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Swagger\Annotation\HyperfServer;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Ramsey\Uuid\Uuid;

use function Hyperf\Support\env;

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

    #[Inject]
    protected ?MigrationAuditService $auditService = null;

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
        $requestId = Uuid::uuid4()->toString();
        $rawBatch = $this->request->input('batch', []);

        if (empty($rawBatch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($rawBatch) > $this->getMaxBatchSize()) {
            return [
                'error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}",
                'code' => 422,
            ];
        }

        $contractId = $this->getContractId();
        $entity = $this->getEntity();

        if ($this->auditService !== null) {
            $this->auditService->open(
                $requestId,
                $contractId,
                $entity,
                $rawBatch,
                $this->getRequestIpAddress(),
                $this->getRequestUserAgent()
            );
        }

        [$batch, $validationErrors] = $this->filterValidRecords($rawBatch);
        [$batch, $skipped, $existingMappings] = $this->filterDuplicates($batch, $contractId);
        [$preparedBatch, $idMappings, $recordContexts] = $this->prepareRecordsForInsert($batch, $contractId);

        if (! empty($preparedBatch)) {
            $results = $this->insertService->insertSync($this->getTable(), $preparedBatch, $this->getConnection());
        } else {
            $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];
        }

        if (! empty($idMappings) && $results['inserted'] > 0) {
            $this->idMappingService->storeBatch($entity, $idMappings, $contractId);
        }

        $response = [
            'inserted' => $results['inserted'],
            'skipped' => count($skipped),
            'failed' => $results['failed'] + count($validationErrors),
            'errors' => array_merge($validationErrors, $results['errors']),
            'id_mappings' => array_replace($existingMappings, $idMappings),
        ];

        $this->auditService?->close($requestId, $response);

        if ($this->auditService?->shouldLogRecords($entity) === true) {
            $this->auditService->logRecords(
                $requestId,
                $contractId,
                $entity,
                array_merge(
                    $this->buildValidationRecordLogs($validationErrors),
                    $this->buildSkippedRecordLogs($skipped),
                    $this->buildSyncInsertRecordLogs($recordContexts, $results)
                )
            );
        }

        return $response;
    }

    protected function asyncMigrate(): array
    {
        $requestId = Uuid::uuid4()->toString();
        $rawBatch = $this->request->input('batch', []);
        $contractId = $this->getContractId();
        $entity = $this->getEntity();

        if ($this->auditService !== null) {
            $this->auditService->open(
                $requestId,
                $contractId,
                $entity,
                $rawBatch,
                $this->getRequestIpAddress(),
                $this->getRequestUserAgent()
            );
        }

        [$batch, $validationErrors] = $this->filterValidRecords($rawBatch);
        [$batch, $skipped, $existingMappings] = $this->filterDuplicates($batch, $contractId);

        $migrationBatch = $this->batchService->create(
            $entity,
            \count($batch),
            $contractId
        );

        $batchId = (string) $migrationBatch->getAttribute('id');

        $this->batchService->markProcessing($batchId);

        [$preparedBatch, $idMappings, $recordContexts] = $this->prepareRecordsForInsert($batch, $contractId, false);

        if (! empty($preparedBatch)) {
            $results = $this->insertService->insertBatch($this->getTable(), $preparedBatch, 0, 0, $this->getConnection());
        } else {
            $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];
        }

        if (! empty($idMappings) && $results['inserted'] > 0) {
            $this->idMappingService->storeBatch($entity, $idMappings, $contractId, $batchId);
        }

        $allErrors = array_merge($validationErrors, $results['errors']);
        $totalFailed = $results['failed'] + \count($validationErrors);

        $this->batchService->markCompleted(
            $batchId,
            $results['inserted'],
            $totalFailed,
            $allErrors ?: null
        );

        $response = [
            'migration_batch_id' => $batchId,
            'entity'             => $entity,
            'total_received'     => \count($rawBatch),
            'status'             => $totalFailed > 0 ? 'completed_with_errors' : 'completed',
            'inserted'           => $results['inserted'],
            'skipped'            => \count($skipped),
            'failed'             => $totalFailed,
            'errors'             => $allErrors,
            'id_mappings'        => array_replace($existingMappings, $idMappings),
            'status_url'         => "/api/v1/migration/status/{$batchId}",
        ];

        $this->auditService?->close($requestId, $response, $batchId);

        if ($this->auditService?->shouldLogRecords($entity) === true) {
            $this->auditService->logRecords(
                $requestId,
                $contractId,
                $entity,
                array_merge(
                    $this->buildValidationRecordLogs($validationErrors),
                    $this->buildSkippedRecordLogs($skipped),
                    $this->buildAsyncInsertRecordLogs($recordContexts, $results)
                )
            );
        }

        return $response;
    }

    /**
     * Gera o UUID para novos registros. Override em subclasses que precisam
     * de UUID ordenado (ex: import_records usa uuid7 para performance de índice).
     */
    protected function generateId(): string
    {
        return Uuid::uuid4()->toString();
    }

    protected function filterDuplicates(array $batch, string $contractId): array
    {
        $legacyIds = array_values(array_unique(array_filter(array_map(
            static fn (array $record): ?string => isset($record['legacy_id']) ? (string) $record['legacy_id'] : null,
            $batch
        ))));

        if (empty($legacyIds)) {
            return [$batch, [], []];
        }

        $existing = $this->idMappingService->resolveMany($this->getEntity(), $legacyIds, $contractId);
        $toInsert = [];
        $skipped = [];
        $existingMappings = [];

        foreach ($batch as $record) {
            $legacyId = $record['legacy_id'] ?? null;

            if ($legacyId !== null && isset($existing[$legacyId])) {
                $skipped[] = [
                    'legacy_id' => (string) $legacyId,
                    'new_id' => $existing[$legacyId],
                ];
                $existingMappings[(string) $legacyId] = $existing[$legacyId];
                continue;
            }

            $toInsert[] = $record;
        }

        return [$toInsert, $skipped, $existingMappings];
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

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>, 2: array<int, array<string, mixed>>}
     */
    protected function prepareRecordsForInsert(array $batch, string $contractId, bool $normalizeStrings = true): array
    {
        $now = date('Y-m-d H:i:s');
        $preparedBatch = [];
        $idMappings = [];
        $recordContexts = [];

        foreach ($batch as $record) {
            if ($normalizeStrings) {
                foreach (array_keys($record) as $field) {
                    if (
                        $field === 'password' ||
                        $field === 'contractor_type' ||
                        $field === 'tax_regime' ||
                        $field === 'format' ||
                        $field === 'movement_type' ||
                        $field === 'sector' ||
                        $field === 'origin' ||
                        $field === 'debit_credit' ||
                        $field === 'records_origin' ||
                        str_ends_with($field, '_id') ||
                        ! is_string($record[$field])
                        || $field === 'status'
                    ) {
                        continue;
                    }

                    if ($field === 'email') {
                        $record[$field] = strtolower($record[$field] ?? '');
                        continue;
                    }

                    $record[$field] = mb_strtoupper((string) ($record[$field] ?? ''));
                }
            }

            $legacyId = $record['legacy_id'] ?? null;
            unset($record['legacy_id']);

            if (empty($record['id'])) {
                $record['id'] = $this->generateId();
            }

            if (! empty($record['password'])) {
                $record['password'] = password_hash($record['password'], PASSWORD_BCRYPT);
            }

            $record['created_at'] = $record['created_at'] ?? $now;
            $record['updated_at'] = $record['updated_at'] ?? $now;

            $record = $this->resolveForeignKeys($record, $contractId);

            if ($legacyId !== null) {
                $idMappings[(string) $legacyId] = $record['id'];
            }

            $preparedBatch[] = $record;
            $recordContexts[] = [
                'legacy_id' => $legacyId !== null ? (string) $legacyId : null,
                'new_id' => $record['id'] ?? null,
            ];
        }

        return [$preparedBatch, $idMappings, $recordContexts];
    }

    protected function getRequestIpAddress(): ?string
    {
        $serverParams = $this->request->getServerParams();

        return isset($serverParams['remote_addr']) ? (string) $serverParams['remote_addr'] : null;
    }

    protected function getRequestUserAgent(): ?string
    {
        return $this->request->header('user-agent', '');
    }

    protected function buildValidationRecordLogs(array $validationErrors): array
    {
        return array_map(static function (array $validationError): array {
            return [
                'legacy_id' => $validationError['legacy_id'] ?? null,
                'new_id' => null,
                'status' => 'validation_error',
                'error_message' => json_encode(
                    $validationError['validation_errors'] ?? [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ];
        }, $validationErrors);
    }

    protected function buildSkippedRecordLogs(array $skipped): array
    {
        return array_map(static function (array $record): array {
            return [
                'legacy_id' => $record['legacy_id'] ?? null,
                'new_id' => $record['new_id'] ?? null,
                'status' => 'skipped_duplicate',
                'error_message' => null,
            ];
        }, $skipped);
    }

    protected function buildSyncInsertRecordLogs(array $recordContexts, array $results): array
    {
        if (empty($recordContexts)) {
            return [];
        }

        $status = ((int) ($results['failed'] ?? 0)) > 0 ? 'failed' : 'inserted';
        $errorMessage = $status === 'failed'
            ? $this->extractErrorMessage($results['errors'][0] ?? null)
            : null;

        return array_map(static function (array $recordContext) use ($status, $errorMessage): array {
            return [
                'legacy_id' => $recordContext['legacy_id'] ?? null,
                'new_id' => $status === 'inserted' ? ($recordContext['new_id'] ?? null) : null,
                'status' => $status,
                'error_message' => $errorMessage,
            ];
        }, $recordContexts);
    }

    protected function buildAsyncInsertRecordLogs(array $recordContexts, array $results): array
    {
        if (empty($recordContexts)) {
            return [];
        }

        $chunkSize = (int) env('MIGRATION_CHUNK_SIZE', 1000);
        if ($chunkSize <= 0) {
            $chunkSize = 1000;
        }

        $failedChunks = [];
        foreach ($results['errors'] ?? [] as $error) {
            if (! is_array($error) || ! array_key_exists('chunk_index', $error)) {
                continue;
            }

            $failedChunks[(int) $error['chunk_index']] = $this->extractErrorMessage($error);
        }

        if (empty($failedChunks)) {
            return array_map(static function (array $recordContext): array {
                return [
                    'legacy_id' => $recordContext['legacy_id'] ?? null,
                    'new_id' => $recordContext['new_id'] ?? null,
                    'status' => 'inserted',
                    'error_message' => null,
                ];
            }, $recordContexts);
        }

        $recordLogs = [];
        foreach (array_chunk($recordContexts, $chunkSize) as $chunkIndex => $chunk) {
            $chunkFailed = array_key_exists($chunkIndex, $failedChunks);

            foreach ($chunk as $recordContext) {
                $recordLogs[] = [
                    'legacy_id' => $recordContext['legacy_id'] ?? null,
                    'new_id' => $chunkFailed ? null : ($recordContext['new_id'] ?? null),
                    'status' => $chunkFailed ? 'failed' : 'inserted',
                    'error_message' => $chunkFailed ? $failedChunks[$chunkIndex] : null,
                ];
            }
        }

        return $recordLogs;
    }

    protected function extractErrorMessage(mixed $error): ?string
    {
        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }

        if (is_array($error) && isset($error['error']) && is_string($error['error'])) {
            return $error['error'];
        }

        return null;
    }
}
