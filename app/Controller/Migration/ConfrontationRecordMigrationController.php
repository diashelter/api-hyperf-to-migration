<?php

declare(strict_types=1);

namespace App\Controller\Migration;

use App\Controller\AbstractMigrationController;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class ConfrontationRecordMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'confrontation_records';
    }

    protected function getEntity(): string
    {
        return 'confrontation_records';
    }

    protected function getMaxBatchSize(): int
    {
        return 1000;
    }

    protected function validationRules(): array
    {
        return [
            'date'              => 'required|date',
            'layout_code'       => 'required|string|max:5',
            'debit_credit'      => 'required|in:D,C',
            'value'             => 'required|numeric',
            'records_origin'    => 'nullable|in:F,B',
            'history'           => 'nullable|string',
            'client_supplier'   => 'nullable|string|max:255',
            'num_doc'           => 'nullable|string|max:255',
            'bank'              => 'nullable|string|max:100',
            'cpf_cnpj'          => 'nullable|string|max:14',
            'conciliated_value' => 'nullable|numeric',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/confrontation-records',
        summary: 'Migrar registros de confrontação (async)',
        description: 'Insere registros de confrontação em lote via processamento paralelo com coroutines Swoole. Fase 9 — depende de confrontations, import_records, imports. Max batch: 1000. Rate limit: 30 req/min. FK legados: legacy_confrontation_id, legacy_import_record_id, legacy_import_id.',
        tags: ['Migration - Async'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/MigrationBatchRequest',
                example: [
                    'batch' => [
                        [
                            'legacy_id'                  => 'CR-001',
                            'date'                       => '2024-01-10',
                            'layout_code'                => 'CSB01',
                            'debit_credit'               => 'D',
                            'value'                      => 1500.00,
                            'records_origin'             => 'F',
                            'history'                    => 'Pagamento fornecedor',
                            'client_supplier'            => 'Fornecedor ABC',
                            'num_doc'                    => 'NF-001234',
                            'conciliated_value'          => 1500.00,
                            'legacy_confrontation_id'    => 'CON-001',
                            'legacy_import_record_id'    => 'IR-0001',
                        ],
                    ],
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Migração processada',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/AsyncMigrationResponse',
                    example: [
                        'migration_batch_id' => '990e8400-e29b-41d4-a716-446655440004',
                        'entity'             => 'confrontation_records',
                        'total_received'     => 1,
                        'status'             => 'completed',
                        'inserted'           => 1,
                        'failed'             => 0,
                        'errors'             => null,
                        'id_mappings'        => [],
                        'status_url'         => '/api/v1/migration/status/990e8400-e29b-41d4-a716-446655440004',
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'confrontation-records')]
    public function migrate(): array
    {
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($batch) > $this->getMaxBatchSize()) {
            return ['error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}", 'code' => 422];
        }

        return $this->asyncMigrate();
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_confrontation_id'])) {
            $record['confrontation_id'] = $this->idMappingService->resolve('confrontations', $record['legacy_confrontation_id'], $contractId) ?? $record['confrontation_id'] ?? null;
            unset($record['legacy_confrontation_id']);
        }

        if (! empty($record['legacy_import_record_id'])) {
            $record['import_record_id'] = $this->idMappingService->resolve('import_records', $record['legacy_import_record_id'], $contractId) ?? $record['import_record_id'] ?? null;
            unset($record['legacy_import_record_id']);
        }

        if (! empty($record['legacy_import_id'])) {
            $record['import_id'] = $this->idMappingService->resolve('imports', $record['legacy_import_id'], $contractId) ?? $record['import_id'] ?? null;
            unset($record['legacy_import_id']);
        }

        return $record;
    }
}
