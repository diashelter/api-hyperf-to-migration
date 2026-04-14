<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Controller\Migration;

use App\Controller\AbstractMigrationController;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class PlanItemMigrationController extends AbstractMigrationController
{
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
            new OA\Response(response: 200, description: 'Replay idempotente (todos os registros já existiam)', content: new OA\JsonContent(ref: '#/components/schemas/SyncMigrationResponse')),
            new OA\Response(response: 201, description: 'Migração concluída com sucesso', content: new OA\JsonContent(ref: '#/components/schemas/SyncMigrationResponse')),
            new OA\Response(response: 207, description: 'Migração parcial — alguns registros falharam', content: new OA\JsonContent(ref: '#/components/schemas/SyncMigrationResponse')),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 413, description: 'Batch excede o limite máximo', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou todos os registros falharam', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
            new OA\Response(response: 500, description: 'Erro interno do servidor', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
        ]
    )]
    #[PostMapping(path: 'plan-items')]
    public function migrate(): PsrResponseInterface
    {
        return $this->syncMigrate();
    }

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

    protected function getConnection(): string
    {
        return 'conciliador_web';
    }

    protected function validationRules(): array
    {
        return [
            'name' => 'required|string|max:70',
            'complete_account' => 'nullable|string|max:20',
            'reduced_account' => 'nullable|string|max:20',
            'type' => 'nullable|string|max:50',
            'origin' => 'nullable|in:C,D,I',
        ];
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
