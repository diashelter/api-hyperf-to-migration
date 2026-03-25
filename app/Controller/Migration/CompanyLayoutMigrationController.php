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
class CompanyLayoutMigrationController extends AbstractMigrationController
{
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
            'type_accounting'          => 'nullable|in:DCH,DC,LA',
            'credit_account'           => 'nullable|string|max:20',
            'debit_account'            => 'nullable|string|max:20',
            'account_fixed'            => 'nullable|boolean',
            'bank'                     => 'nullable|string|max:50',
            'value_debit'              => 'nullable|string|max:50',
            'value_code_history_debit' => 'nullable|string|max:50',
            'value_history_debit'      => 'nullable|string|max:50',
            'fees_debit'               => 'nullable|string|max:50',
            'fees_code_history_debit'  => 'nullable|string|max:50',
            'fees_history_debit'       => 'nullable|string|max:50',
            'fine_debit'               => 'nullable|string|max:50',
            'discount_debit'           => 'nullable|string|max:50',
            'others_debit'             => 'nullable|string|max:50',
            'refunds_debit'            => 'nullable|string|max:50',
            'rates_debit'              => 'nullable|string|max:50',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/company-layouts',
        summary: 'Migrar vínculos empresa × layout (CRÍTICO)',
        description: 'Insere vínculos company_layout em lote (síncrono). Fase 5 da migração — tabela pivot crítica entre companies e layouts. SEM este registro não é possível criar imports. Depende de companies e layouts. Max batch: 200. FK legados: legacy_company_id, legacy_layout_imp, legacy_layout_exp.',
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
                            'legacy_id'         => 'CL-001',
                            'type_accounting'   => 'DC',
                            'credit_account'    => '1.1.01',
                            'debit_account'     => '4.1.01',
                            'account_fixed'     => false,
                            'legacy_company_id' => 'EMP-001',
                            'legacy_layout_imp' => 'LAY-001',
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
                        'id_mappings' => ['CL-001' => 'fff66666-0000-0000-0000-000000000001'],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'company-layouts')]
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
        if (! empty($record['legacy_company_id'])) {
            $record['company_id'] = $this->idMappingService->resolve('companies', $record['legacy_company_id'], $contractId) ?? $record['company_id'] ?? null;
            unset($record['legacy_company_id']);
        }

        // layout_imp = FK para layouts (layout de importação)
        if (! empty($record['legacy_layout_imp'])) {
            $record['layout_imp'] = $this->idMappingService->resolve('layouts', $record['legacy_layout_imp'], $contractId) ?? $record['layout_imp'] ?? null;
            unset($record['legacy_layout_imp']);
        }

        // layout_exp = FK para layout_admins (layout de exportação — externo ao migrador)
        if (! empty($record['legacy_layout_exp'])) {
            $record['layout_exp'] = $this->idMappingService->resolve('layout_admins', $record['legacy_layout_exp'], $contractId) ?? $record['layout_exp'] ?? null;
            unset($record['legacy_layout_exp']);
        }

        return $record;
    }
}
