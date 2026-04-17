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
    private const DEBIT_FIELD_MAP = [
        'Valor' => 'value',
        'Juros' => 'fees',
        'Multa' => 'fine',
        'Desconto' => 'discount',
        'Outros' => 'others',
        'Devolução' => 'refunds',
        'Tarifas' => 'rates',
    ];

    private const CREDIT_FIELD_MAP = [
        'Valor' => 'value',
        'Juros' => 'interest',
        'Multa' => 'fine',
        'Desconto' => 'discount',
        'Outros' => 'others',
        'Devolução' => 'refunds',
        'Tarifas' => 'rates',
    ];

    #[OA\Post(
        path: '/api/v1/migration/company-layout-fixed-accounts',
        summary: 'Migrar contas fixas de vínculos empresa × layout',
        description: <<<'DESC'
        Insere registros de contas fixas em `company_layout_fixed_accounts` em lote (síncrono). Fase 6 da migração — depende de `company_layout` já migrado. Max batch: 200.

        **Payload por registro:**
        - `legacy_id` (required): identificador legado do vínculo
        - `legacy_company_layout_id` (required): FK legada resolvida para `company_layout_id`
        - `bank_account` (nullable): código da conta bancária (`conta_fixa` no legado)
        - `contas_fixas_modelo` (required): JSON string do legado contendo arrays `D` (débito) e `C` (crédito). O parse e expansão em colunas acontece no controller.

        **Mapeamento do JSON `contas_fixas_modelo` para colunas:**

        Cada item tem `{campo, conta, cod_hist, hist_person}`. `conta`→`{prefix}_{side}`, `cod_hist`→`{prefix}_code_history_{side}`, `hist_person`→`{prefix}_history_{side}`. Strings vazias viram `null`.

        Prefixos (`D` → `_debit`, `C` → `_credit`):
        - `Valor` → `value`
        - `Juros` → `fees` (em `D`) / `interest` (em `C`)
        - `Multa` → `fine`
        - `Desconto` → `discount`
        - `Outros` → `others`
        - `Devolução` → `refunds`
        - `Tarifas` → `rates`
        - `Taxas` → **descartado** (sem coluna correspondente)

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
                            'legacy_id' => '292',
                            'legacy_company_layout_id' => 'CL-001',
                            'bank_account' => '84',
                            'contas_fixas_modelo' => '{"D":[{"campo":"Valor","conta":"1.1.01","la":"","cod_hist":"001","hist_person":"RECEBIMENTO"},{"campo":"Juros","conta":"","la":"","cod_hist":"","hist_person":""}],"C":[{"campo":"Valor","conta":"4.1.01","la":"","cod_hist":"","hist_person":""},{"campo":"Juros","conta":"4.1.02","la":"","cod_hist":"","hist_person":""}]}',
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
            'legacy_id' => 'required|string|max:255',
            'legacy_company_layout_id' => 'required|string|max:255',
            'bank_account' => 'nullable|string|max:100',
            'contas_fixas_modelo' => 'required|string',
        ];
    }

    protected function filterValidRecords(array $batch): array
    {
        $rules = $this->validationRules();
        $validRecords = [];
        $validationErrors = [];

        foreach ($batch as $index => $record) {
            $validator = $this->validatorFactory->make($record, $rules);

            if ($validator->fails()) {
                $validationErrors[] = [
                    'index' => $index,
                    'legacy_id' => $record['legacy_id'] ?? null,
                    'validation_errors' => $validator->errors()->toArray(),
                ];
                continue;
            }

            $expanded = $this->expandContasFixasModelo($record);
            if ($expanded === null) {
                $validationErrors[] = [
                    'index' => $index,
                    'legacy_id' => $record['legacy_id'] ?? null,
                    'validation_errors' => ['contas_fixas_modelo' => ['invalid JSON or missing D/C keys']],
                ];
                continue;
            }

            $validRecords[] = $expanded;
        }

        return [$validRecords, $validationErrors];
    }

    protected function getForeignKeyMap(): array
    {
        return [
            'legacy_company_layout_id' => 'company_layout',
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

    private function expandContasFixasModelo(array $record): ?array
    {
        $raw = $record['contas_fixas_modelo'] ?? null;
        if (! \is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! \is_array($decoded)) {
            return null;
        }
        if (! isset($decoded['D'], $decoded['C']) || ! \is_array($decoded['D']) || ! \is_array($decoded['C'])) {
            return null;
        }

        $record = $this->applySideMapping($record, $decoded['D'], self::DEBIT_FIELD_MAP, 'debit');
        $record = $this->applySideMapping($record, $decoded['C'], self::CREDIT_FIELD_MAP, 'credit');

        unset($record['contas_fixas_modelo']);

        return $record;
    }

    /**
     * @param array<int, mixed> $items
     * @param array<string, string> $fieldMap
     */
    private function applySideMapping(array $record, array $items, array $fieldMap, string $side): array
    {
        foreach ($items as $item) {
            if (! \is_array($item) || ! isset($item['campo'])) {
                continue;
            }
            $prefix = $fieldMap[$item['campo']] ?? null;
            if ($prefix === null) {
                continue;
            }
            $record[$prefix . '_' . $side] = $this->nullIfEmpty($item['conta'] ?? null);
            $record[$prefix . '_code_history_' . $side] = $this->nullIfEmpty($item['cod_hist'] ?? null);
            $record[$prefix . '_history_' . $side] = $this->nullIfEmpty($item['hist_person'] ?? null);
        }

        return $record;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
