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
class RulesSharingMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'rules_sharings';
    }

    protected function getEntity(): string
    {
        return 'rules_sharings';
    }

    protected function getMaxBatchSize(): int
    {
        return 100;
    }

    protected function validationRules(): array
    {
        return [
            'code' => 'required|integer',
            'name' => 'required|string|max:30',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/rules-sharings',
        summary: 'Migrar grupos de regras (rules sharings)',
        description: 'Insere grupos de compartilhamento de regras em lote (síncrono). Fase 3 — depende de contracts. Max batch: 100. FK legados: legacy_contract_id.',
        tags: ['Migration - Sync'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/MigrationBatchRequest',
                example: [
                    'batch' => [
                        ['legacy_id' => 'RS-001', 'code' => 1, 'name' => 'Grupo Padrão', 'legacy_contract_id' => 'LEG-001'],
                        ['legacy_id' => 'RS-002', 'code' => 2, 'name' => 'Grupo Fiscal',  'legacy_contract_id' => 'LEG-001'],
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
                        'id_mappings' => ['RS-001' => 'aaa11111-0000-0000-0000-000000000001', 'RS-002' => 'aaa11111-0000-0000-0000-000000000002'],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'rules-sharings')]
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
        if (! empty($record['legacy_contract_id'])) {
            $record['contract_id'] = $this->idMappingService->resolve('contracts', $record['legacy_contract_id'], $contractId) ?? $record['contract_id'] ?? null;
            unset($record['legacy_contract_id']);
        }

        return $record;
    }
}
