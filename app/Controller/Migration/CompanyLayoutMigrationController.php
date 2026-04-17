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
use App\Service\LookupCacheService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class CompanyLayoutMigrationController extends AbstractMigrationController
{
    #[Inject]
    protected LookupCacheService $lookupCacheService;

    #[OA\Post(
        path: '/api/v1/migration/company-layouts',
        summary: 'Migrar vínculos empresa × layout (CRÍTICO)',
        description: <<<'DESC'
        Insere vínculos company_layout em lote (síncrono). Fase 5 da migração — tabela pivot crítica entre companies e layouts. SEM este registro não é possível criar imports. Max batch: 200.

        **Resolução de FKs legadas:**
        - `legacy_company_id` → resolve via `migration_id_mappings` (entidade `companies`)
        - `legacy_layout_imp` → resolve via `migration_id_mappings` (entidade `layouts`) — layout de importação migrado
        - `legacy_layout_exp` → resolve via `lookup_cache` (entidade `layouts_admin`) — layout de exportação pré-existente no sistema; pode ser do tipo IMP ou EXP conforme cadastrado no lookup_cache

        **Pré-requisito:** `php bin/hyperf.php migration:seed-lookups layouts_admin`
        DESC,
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
                            'legacy_id' => 'CL-001',
                            'type_accounting' => 'DC',
                            'credit_account' => '1.1.01',
                            'debit_account' => '4.1.01',
                            'account_fixed' => false,
                            'legacy_company_id' => 'EMP-001',
                            'legacy_layout_imp' => 'LAY-001',
                            'legacy_layout_exp' => 'EXP-BRADESCO',
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
    #[PostMapping(path: 'company-layouts')]
    public function migrate(): PsrResponseInterface
    {
        return $this->syncMigrate();
    }

    protected function getTable(): string
    {
        return 'company_layout';
    }

    protected function getEntity(): string
    {
        return 'company_layout';
    }

    protected function getMaxBatchSize(): int
    {
        return 200;
    }

    protected function getConnection(): string
    {
        return 'conciliador_web';
    }

    protected function validationRules(): array
    {
        return [
            'type_accounting' => 'nullable|in:DCH,DC,LA',
            'credit_account' => 'nullable|string|max:20',
            'debit_account' => 'nullable|string|max:20',
            'account_fixed' => 'nullable|boolean',
        ];
    }

    protected function getForeignKeyMap(): array
    {
        return [
            'legacy_company_id' => 'companies',
            'legacy_layout_imp' => 'layouts',
        ];
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_company_id'])) {
            $record['company_id'] = $this->idMappingService->resolve('companies', $record['legacy_company_id'], $contractId) ?? $record['company_id'] ?? null;
            unset($record['legacy_company_id']);
        }

        // layout_imp = FK para layouts (layout de importação)
        if (! empty($record['legacy_layout_imp'])) {
            $record['layout_imp'] = $this->idMappingService->resolve('layouts', $record['legacy_layout_imp'], $contractId) ?? $record['layout_imp'] ?? null;
            unset($record['legacy_layout_imp']);
        }

        // layout_exp = FK para layouts_admin (layout de exportação — externo ao migrador, resolvido via lookup_cache)
        if (! empty($record['legacy_layout_exp'])) {
            $record['layout_exp'] = $this->lookupCacheService->resolve('layouts_admin', $record['legacy_layout_exp']) ?? $record['layout_exp'] ?? null;
            unset($record['legacy_layout_exp']);
        }

        return $record;
    }
}
