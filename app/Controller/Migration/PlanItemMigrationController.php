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
class PlanItemMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'plan_items';
    }

    protected function getEntity(): string
    {
        return 'plan_items';
    }

    protected function getMaxBatchSize(): int
    {
        return 1000;
    }

    protected function validationRules(): array
    {
        return [
            'name'             => 'required|string|max:70',
            'complete_account' => 'nullable|string|max:20',
            'reduced_account'  => 'nullable|string|max:20',
            'type'             => 'nullable|string|max:50',
            'origin'           => 'nullable|in:C,D,I',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/plan-items',
        summary: 'Migrar itens do plano de contas',
        description: 'Insere itens do plano em lote (síncrono). Fase 3 — depende de plans. Max batch: 1000. FK legados: legacy_plan_id.',
        tags: ['Migration - Sync'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/MigrationBatchRequest',
                example: [
                    'batch' => [
                        ['legacy_id' => 'PI-001', 'name' => 'Receitas Operacionais', 'complete_account' => '3.1.01.001', 'reduced_account' => '3101', 'type' => 'Receita', 'origin' => 'C', 'legacy_plan_id' => 'PLN-001'],
                        ['legacy_id' => 'PI-002', 'name' => 'Despesas Administrativas', 'complete_account' => '4.1.01.001', 'reduced_account' => '4101', 'type' => 'Despesa', 'origin' => 'D', 'legacy_plan_id' => 'PLN-001'],
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
                        'inserted'    => 2,
                        'failed'      => 0,
                        'errors'      => [],
                        'id_mappings' => ['PI-001' => 'ccc33333-0000-0000-0000-000000000001', 'PI-002' => 'ccc33333-0000-0000-0000-000000000002'],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'plan-items')]
    public function migrate(): array
    {
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (count($batch) > $this->getMaxBatchSize()) {
            return ['error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}", 'code' => 422];
        }

        return $this->syncMigrate();
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_plan_id'])) {
            $record['plan_id'] = $this->idMappingService->resolve('plans', $record['legacy_plan_id'], $contractId) ?? $record['plan_id'] ?? null;
            unset($record['legacy_plan_id']);
        }

        return $record;
    }
}
