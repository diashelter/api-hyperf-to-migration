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
class PeopleVinculatedMigrationController extends AbstractMigrationController
{
    #[OA\Post(
        path: '/api/v1/migration/people-vinculated',
        summary: 'Migrar vínculos de participantes',
        description: 'Insere vínculos people_vinculated em lote (síncrono). Fase 6b — depende de peoples, companies e/ou rules_sharings. CONSTRAINT: exatamente um de company_id OU rules_sharing_id deve ser preenchido. Max batch: 1000. FK legados: legacy_people_id, legacy_company_id, legacy_rules_sharing_id.',
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
                            'legacy_id' => 'PV-001',
                            'debit_account' => '1.1.01',
                            'credit_account' => '4.1.01',
                            'participant' => 'Maria Santos',
                            'legacy_people_id' => 'PEO-001',
                            'legacy_company_id' => 'EMP-001',
                        ],
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
    #[PostMapping(path: 'people-vinculated')]
    public function migrate(): PsrResponseInterface
    {
        return $this->syncMigrate();
    }

    protected function getTable(): string
    {
        return 'people_vinculated';
    }

    protected function getEntity(): string
    {
        return 'people_vinculated';
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
            'debit_account' => 'nullable|string|max:10',
            'credit_account' => 'nullable|string|max:10',
            'participant' => 'nullable|string|max:100',
            'vinculated_name' => 'nullable|string|max:150',
        ];
    }

    protected function getForeignKeyMap(): array
    {
        return [
            'legacy_people_id' => 'peoples',
            'legacy_company_id' => 'companies',
            'legacy_rules_sharing_id' => 'rules_sharings',
        ];
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        $record['people_id'] = null;
        if (! empty($record['legacy_people_id'])) {
            $record['people_id'] = $this->idMappingService->resolve('peoples', $record['legacy_people_id'], $contractId) ?? $record['people_id'] ?? null;
        }
        unset($record['legacy_people_id']);

        $record['company_id'] = null;
        if (! empty($record['legacy_company_id'])) {
            $record['company_id'] = $this->idMappingService->resolve('companies', $record['legacy_company_id'], $contractId) ?? $record['company_id'] ?? null;
        }
        unset($record['legacy_company_id']);

        $record['rules_sharing_id'] = null;
        if (! empty($record['legacy_rules_sharing_id'])) {
            $record['rules_sharing_id'] = $this->idMappingService->resolve('rules_sharings', $record['legacy_rules_sharing_id'], $contractId) ?? $record['rules_sharing_id'] ?? null;
        }
        unset($record['legacy_rules_sharing_id']);

        return $record;
    }
}
