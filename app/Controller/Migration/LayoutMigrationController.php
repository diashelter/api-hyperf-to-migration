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
class LayoutMigrationController extends AbstractMigrationController
{
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
        return 50;
    }

    protected function validationRules(): array
    {
        return [
            'name'          => 'required|string|max:255',
            'format'        => 'required|in:Excel,OFX,TXT,CSV,CNAB240,CNAB400',
            'sector'        => 'nullable|in:Contábil,Fiscal',
            'movement_type' => 'required|in:Ambos,Pagar,Receber',
            'start_row'     => 'required|integer|min:1',
            'date_column'                    => 'required|string|max:10',
            'history_column'                 => 'required|string|max:10',
            'debit_value_column'             => 'nullable|string|max:10',
            'credit_value_column'            => 'nullable|string|max:10',
            'num_doc_column'                 => 'nullable|string|max:10',
            'client_supplier_column'         => 'nullable|string|max:10',
            'bank_column'                    => 'nullable|string|max:10',
            'filial_column'                  => 'nullable|integer',
            'debit_credit_column'            => 'nullable|string|max:10',
            'cpf_cnpj_column'                => 'nullable|integer',
            'additional_information_column'  => 'nullable|integer',
            'additional_information_2_column'=> 'nullable|integer',
            'additional_information_3_column'=> 'nullable|integer',
            'complement_column'              => 'nullable|integer',
            'debit_account_column'           => 'nullable|integer',
            'credit_account_column'          => 'nullable|integer',
            'third_party_participant_column' => 'nullable|integer',
            'parcel_separator'               => 'nullable|string|max:10',
            'consider_previous_date'             => 'nullable|boolean',
            'consider_previous_client_supplier'  => 'nullable|boolean',
            'consider_previous_history'          => 'nullable|boolean',
            'consider_previous_filial'           => 'nullable|boolean',
            'consider_previous_bank'             => 'nullable|boolean',
            'invert_sign'                        => 'nullable|boolean',
            'import_blocked_entries'             => 'nullable|boolean',
            'bank_statement'                     => 'nullable|boolean',
            'participant_marking_enabled'        => 'nullable|boolean',
            'consider_dc_for_accounting_rules'              => 'nullable|boolean',
            'consider_history_for_accounting_rules'         => 'nullable|boolean',
            'consider_participant_doc_for_accounting_rules' => 'nullable|boolean',
            'consider_participant_for_accounting_rules'     => 'nullable|boolean',
            'consider_bank_for_accounting_rules'            => 'nullable|boolean',
            'consider_filial_for_accounting_rules'          => 'nullable|boolean',
            'consider_additional_info_for_accounting_rules' => 'nullable|boolean',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/layouts',
        summary: 'Migrar layouts',
        description: 'Insere layouts em lote (síncrono). Fase 5 — depende de contracts. Max batch: 50 (objeto complexo com ~95 campos). FK legados: legacy_contract_id.',
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
                            'legacy_id'          => 'LAY-001',
                            'name'               => 'Layout Extrato CSV',
                            'format'             => 'CSV',
                            'movement_type'      => 'Ambos',
                            'start_row'          => 2,
                            'date_column'        => 'A',
                            'history_column'     => 'B',
                            'debit_value_column' => 'C',
                            'credit_value_column'=> 'D',
                            'legacy_contract_id' => 'LEG-001',
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

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        // code é gerado automaticamente pelo banco — não aceitar do payload
        unset($record['code']);

        if (! empty($record['legacy_contract_id'])) {
            $record['contract_id'] = $this->idMappingService->resolve('contracts', $record['legacy_contract_id'], $contractId) ?? $record['contract_id'] ?? null;
            unset($record['legacy_contract_id']);
        }

        return $record;
    }
}
