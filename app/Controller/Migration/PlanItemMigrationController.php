<?php

declare(strict_types=1);

namespace App\Controller\Migration;

use App\Controller\AbstractMigrationController;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use OpenApi\Attributes as OA;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
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
            content: new OA\JsonContent(ref: '#/components/schemas/MigrationBatchRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Migração concluída', content: new OA\JsonContent(ref: '#/components/schemas/SyncMigrationResponse')),
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
