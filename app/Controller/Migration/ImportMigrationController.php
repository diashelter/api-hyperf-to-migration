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
class ImportMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'imports';
    }

    protected function getEntity(): string
    {
        return 'imports';
    }

    protected function getMaxBatchSize(): int
    {
        return 100;
    }

    protected function validationRules(): array
    {
        return [
            'name'             => 'required|string|max:255',
            'total_files'      => 'required|integer|min:1',
            'initial_period'   => 'nullable|date',
            'final_period'     => 'nullable|date',
            'previous_balance' => 'nullable|numeric',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/imports',
        summary: 'Migrar importações',
        description: 'Insere importações em lote (síncrono). Fase 8 — depende de users, companies, contracts, layouts. Max batch: 100. FK legados: legacy_user_id, legacy_company_id, legacy_contract_id, legacy_company_layout_id.',
        tags: ['Migration - Sync'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/MigrationBatchRequest',
                example: [
                    'batch' => [
                        [
                            'legacy_id'                => 'IMP-001',
                            'name'                     => 'Extrato Jan/2024',
                            'total_files'              => 1,
                            'initial_period'           => '2024-01-01',
                            'final_period'             => '2024-01-31',
                            'previous_balance'         => 1500.00,
                            'legacy_user_id'           => 'USR-001',
                            'legacy_company_id'        => 'EMP-001',
                            'legacy_contract_id'       => 'LEG-001',
                            'legacy_company_layout_id' => 'CL-001',
                        ],
                    ],
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Migração concluída',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/SyncMigrationResponse',
                    example: [
                        'inserted'    => 1,
                        'failed'      => 0,
                        'errors'      => [],
                        'id_mappings' => ['IMP-001' => 'iii99999-0000-0000-0000-000000000001'],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'imports')]
    public function migrate(): array
    {
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($batch) > $this->getMaxBatchSize()) {
            return ['error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}", 'code' => 422];
        }

        return $this->syncMigrate();
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_user_id'])) {
            $record['user_id'] = $this->idMappingService->resolve('users', $record['legacy_user_id'], $contractId) ?? $record['user_id'] ?? null;
            unset($record['legacy_user_id']);
        }

        if (! empty($record['legacy_company_id'])) {
            $record['company_id'] = $this->idMappingService->resolve('companies', $record['legacy_company_id'], $contractId) ?? $record['company_id'] ?? null;
            unset($record['legacy_company_id']);
        }

        if (! empty($record['legacy_contract_id'])) {
            $record['contract_id'] = $this->idMappingService->resolve('contracts', $record['legacy_contract_id'], $contractId) ?? $record['contract_id'] ?? null;
            unset($record['legacy_contract_id']);
        }

        if (! empty($record['legacy_company_layout_id'])) {
            $record['company_layout_id'] = $this->idMappingService->resolve('company_layout', $record['legacy_company_layout_id'], $contractId) ?? $record['company_layout_id'] ?? null;
            unset($record['legacy_company_layout_id']);
        }

        return $record;
    }
}
