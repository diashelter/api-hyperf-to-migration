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

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class LayoutMigrationController extends AbstractMigrationController
{
    #[Inject]
    protected LookupCacheService $lookupCacheService;

    #[OA\Post(
        path: '/api/v1/migration/layouts',
        summary: 'Migrar layouts',
        description: <<<'DESC'
        Insere layouts em lote (síncrono). Fase 4a — depende de contracts. Deve ser executado antes ou em paralelo com companies (Fase 4b), pois company_layout depende de ambos. Max batch: 100 (objeto complexo com ~95 campos). FK legados: legacy_contract_id.

        **Campo `code`:**
        Códigos entre 1000–5000 são tratados como layouts de contrato (sem `layouts_admin_id`).
        Códigos fora desse intervalo são resolvidos via `lookup_cache` (entidade `layouts_admin`) e preenchem `layouts_admin_id`.
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
                            'legacy_id' => 'LAY-001',
                            'name' => 'Layout Extrato CSV',
                            'format' => 'CSV',
                            'movement_type' => 'Ambos',
                            'start_row' => 2,
                            'code' => 1001,
                            'date_column' => 'A',
                            'history_column' => 'B',
                            'debit_value_column' => 'C',
                            'credit_value_column' => 'D',
                            'legacy_contract_id' => 'CONTRACT-001',
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
                        'inserted' => 1,
                        'failed' => 0,
                        'errors' => [],
                        'id_mappings' => ['LAY-001' => 'ddd44444-0000-0000-0000-000000000001'],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'layouts')]
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

    protected function getTable(): string
    {
        return 'layouts';
    }

    protected function getEntity(): string
    {
        return 'layouts';
    }

    protected function getMaxBatchSize(): int
    {
        return 100;
    }

    protected function getConnection(): string
    {
        return 'conciliador_web';
    }

    protected function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'format' => 'required|string',
            'sector' => 'nullable|in:Contábil,Fiscal',
            'movement_type' => 'required|in:Ambos,Pagar,Receber',
            'start_row' => 'required|integer|min:1',
            'date_column' => 'nullable|string|max:10',
            'history_column' => 'nullable|string|max:10',
            'debit_value_column' => 'nullable|string|max:10',
            'credit_value_column' => 'nullable|string|max:10',
            'num_doc_column' => 'nullable|string|max:10',
            'client_supplier_column' => 'nullable|string|max:10',
            'bank_column' => 'nullable|string|max:10',
            'filial_column' => 'nullable|string|max:10',
            'debit_credit_column' => 'nullable|string|max:10',
            'cpf_cnpj_column' => 'nullable|string|max:10',
            'additional_information_column' => 'nullable|string|max:10',
            'additional_information_2_column' => 'nullable|string|max:10',
            'additional_information_3_column' => 'nullable|string|max:10',
            'complement_column' => 'nullable|string|max:10',
            'debit_account_column' => 'nullable|string|max:10',
            'credit_account_column' => 'nullable|string|max:10',
            'third_party_participant_column' => 'nullable|string|max:10',
            'parcel_separator' => 'nullable|string|max:10',
            'consider_previous_date' => 'nullable|boolean',
            'consider_previous_client_supplier' => 'nullable|boolean',
            'consider_previous_history' => 'nullable|boolean',
            'consider_previous_filial' => 'nullable|boolean',
            'consider_previous_bank' => 'nullable|boolean',
            'invert_sign' => 'nullable|boolean',
            'import_blocked_entries' => 'nullable|boolean',
            'bank_statement' => 'nullable|boolean',
            'participant_marking_enabled' => 'nullable|boolean',
            'consider_dc_for_accounting_rules' => 'nullable|boolean',
            'consider_history_for_accounting_rules' => 'nullable|boolean',
            'consider_participant_doc_for_accounting_rules' => 'nullable|boolean',
            'consider_participant_for_accounting_rules' => 'nullable|boolean',
            'consider_bank_for_accounting_rules' => 'nullable|boolean',
            'consider_filial_for_accounting_rules' => 'nullable|boolean',
            'consider_additional_info_for_accounting_rules' => 'nullable|boolean',
        ];
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        // // code é gerado automaticamente pelo banco — não aceitar do payload
        // unset($record['code']);

        $record = $this->resolveContractIdFK($record, $contractId);

        if (isset($record['code']) && ($record['code'] < 1000 || $record['code'] > 5000)) {
            $record['layouts_admin_id'] = $this->lookupCacheService->resolve('layouts_admin', $record['code']);
        }

        if (! empty($record['reference_layout'])) {
            $record['reference_layout'] = $this->idMappingService->resolve('layouts', $record['reference_layout'], $contractId);
        }

        if (isset($record['day_to_add_when_import']) && $record['day_to_add_when_import'] > 999) {
            $record['day_to_add_when_import'] = 999;
        }

        return $record;
    }
}
