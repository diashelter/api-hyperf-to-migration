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
class CompanyLayoutFixedAccountMigrationController extends AbstractMigrationController
{
    #[OA\Post(
        path: '/api/v1/migration/company-layout-fixed-accounts',
        summary: 'Migrar contas fixas de vínculos empresa × layout',
        description: <<<'DESC'
        Insere registros de contas fixas em `company_layout_fixed_accounts` em lote (síncrono). Fase 6 da migração — depende de `company_layout` já migrado. Max batch: 200.

        **Mapeamento do legado (`contas_fixas_modelo`):**
        - `D.Valor` → `value_debit` / `value_code_history_debit` / `value_history_debit`
        - `D.Juros` → `fees_debit` / `fees_code_history_debit` / `fees_history_debit`
        - `D.Multa` → `fine_debit` / `fine_code_history_debit` / `fine_history_debit`
        - `D.Desconto` → `discount_debit` / `discount_code_history_debit` / `discount_history_debit`
        - `D.Outros` → `others_debit` / `others_code_history_debit` / `others_history_debit`
        - `D.Devolução` → `refunds_debit` / `refunds_code_history_debit` / `refunds_history_debit`
        - `D.Taxas` → `rates_debit` / `rates_code_history_debit` / `rates_history_debit`
        - `D.Tarifas` → **descartado** (sem coluna correspondente)
        - Mesmo padrão para `C.*` → `*_credit`
        - `conta_fixa` (legado) → `bank`

        **Resolução de FKs legadas:**
        - `legacy_company_layout_id` → resolve via `migration_id_mappings` (entidade `company_layout`)

        **Pré-requisito:** migração de `company_layout` concluída (entidade `company_layout` deve existir em `migration_id_mappings`)
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
                            'legacy_id' => 'CFA-001',
                            'legacy_company_layout_id' => 'CL-001',
                            'bank' => '84',
                            'is_default_account' => false,
                            'value_debit' => '1.1.01',
                            'value_code_history_debit' => '001',
                            'value_history_debit' => 'RECEBIMENTO',
                            'value_credit' => '4.1.01',
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
    #[PostMapping(path: 'company-layout-fixed-accounts')]
    public function migrate(): PsrResponseInterface
    {
        return $this->syncMigrate();
    }

    protected function getTable(): string
    {
        return 'company_layout_fixed_accounts';
    }

    protected function getEntity(): string
    {
        return 'company_layout_fixed_accounts';
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
            'is_default_account' => 'nullable|boolean',
            'bank' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:100',
            // debit
            'value_debit' => 'nullable|string|max:50',
            'value_code_history_debit' => 'nullable|string|max:50',
            'value_history_debit' => 'nullable|string|max:50',
            'fees_debit' => 'nullable|string|max:50',
            'fees_code_history_debit' => 'nullable|string|max:50',
            'fees_history_debit' => 'nullable|string|max:50',
            'fine_debit' => 'nullable|string|max:50',
            'fine_code_history_debit' => 'nullable|string|max:50',
            'fine_history_debit' => 'nullable|string|max:50',
            'discount_debit' => 'nullable|string|max:50',
            'discount_code_history_debit' => 'nullable|string|max:50',
            'discount_history_debit' => 'nullable|string|max:50',
            'others_debit' => 'nullable|string|max:50',
            'others_code_history_debit' => 'nullable|string|max:50',
            'others_history_debit' => 'nullable|string|max:50',
            'refunds_debit' => 'nullable|string|max:50',
            'refunds_code_history_debit' => 'nullable|string|max:50',
            'refunds_history_debit' => 'nullable|string|max:50',
            'rates_debit' => 'nullable|string|max:50',
            'rates_code_history_debit' => 'nullable|string|max:50',
            'rates_history_debit' => 'nullable|string|max:50',
            // credit
            'value_credit' => 'nullable|string|max:50',
            'value_code_history_credit' => 'nullable|string|max:50',
            'value_history_credit' => 'nullable|string|max:50',
            'fees_credit' => 'nullable|string|max:50',
            'fees_code_history_credit' => 'nullable|string|max:50',
            'fees_history_credit' => 'nullable|string|max:50',
            'fine_credit' => 'nullable|string|max:50',
            'fine_code_history_credit' => 'nullable|string|max:50',
            'fine_history_credit' => 'nullable|string|max:50',
            'discount_credit' => 'nullable|string|max:50',
            'discount_code_history_credit' => 'nullable|string|max:50',
            'discount_history_credit' => 'nullable|string|max:50',
            'others_credit' => 'nullable|string|max:50',
            'others_code_history_credit' => 'nullable|string|max:50',
            'others_history_credit' => 'nullable|string|max:50',
            'refunds_credit' => 'nullable|string|max:50',
            'refunds_code_history_credit' => 'nullable|string|max:50',
            'refunds_history_credit' => 'nullable|string|max:50',
            'rates_credit' => 'nullable|string|max:50',
            'rates_code_history_credit' => 'nullable|string|max:50',
            'rates_history_credit' => 'nullable|string|max:50',
        ];
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_company_layout_id'])) {
            $record['company_layout_id'] = $this->idMappingService->resolve('company_layout', $record['legacy_company_layout_id'], $contractId) ?? $record['company_layout_id'] ?? null;
            unset($record['legacy_company_layout_id']);
        }

        return $record;
    }
}
