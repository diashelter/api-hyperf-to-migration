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
class RuleMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'rules';
    }

    protected function getEntity(): string
    {
        return 'rules';
    }

    protected function getMaxBatchSize(): int
    {
        return 500;
    }

    protected function getConnection(): string
    {
        return 'conciliador_web';
    }

    protected function validationRules(): array
    {
        return [
            'debit_credit'                 => 'nullable|in:D,C',
            'cpf_cnpj'                     => 'nullable|string',
            'client_supplier'              => 'nullable|string',
            'history'                      => 'nullable|string',
            'bank'                         => 'nullable|string',
            'filial'                       => 'nullable|string',
            'additional_information'       => 'nullable|string',
            'additional_information_2'     => 'nullable|string',
            'additional_information_3'     => 'nullable|string',
            'token'                        => 'nullable|string',
            'id_history'                   => 'nullable|string|max:10',
            'id_debit'                     => 'nullable|string|max:10',
            'id_credit'                    => 'nullable|string|max:10',
            'id_history_exp'               => 'nullable|string',
            'id_participant_credit'        => 'nullable|string|max:10',
            'id_participant_debit'         => 'nullable|string|max:10',
            'id_cc_credit'                 => 'nullable|string|max:10',
            'id_cc_debit'                  => 'nullable|string|max:10',
            'exclusive'                    => 'nullable|boolean',
            'reprocess'                    => 'nullable|boolean',
            'invalid'                      => 'nullable|boolean',
            'sort_order'                   => 'nullable|integer',
            'automatic_launch'             => 'nullable|boolean',
            'rule_extra'                   => 'nullable|string',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/rules',
        summary: 'Migrar regras de conciliação (async)',
        description: 'Insere regras em lote via processamento paralelo com coroutines Swoole. Fase 7 — depende de companies, layouts, contracts. Max batch: 500. Rate limit: 30 req/min. FK legados: legacy_company_id, legacy_layout_id, legacy_contract_id.',
        tags: ['Migration - Async'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/MigrationBatchRequest',
                example: [
                    'batch' => [
                        [
                            'legacy_id'          => 'RUL-001',
                            'history'            => 'Pagamento fornecedor',
                            'client_supplier'    => 'Fornecedor ABC',
                            'debit_credit'       => 'D',
                            'id_history'         => 'A',
                            'id_debit'           => 'B',
                            'id_credit'          => 'C',
                            'exclusive'          => false,
                            'sort_order'         => 1,
                            'automatic_launch'   => true,
                            'legacy_company_id'  => 'EMP-001',
                            'legacy_layout_id'   => 'LAY-001',
                            'legacy_contract_id' => 'LEG-001',
                        ],
                    ],
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Migração processada',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/AsyncMigrationResponse',
                    example: [
                        'migration_batch_id' => '880e8400-e29b-41d4-a716-446655440003',
                        'entity'             => 'rules',
                        'total_received'     => 1,
                        'status'             => 'completed',
                        'inserted'           => 1,
                        'failed'             => 0,
                        'errors'             => null,
                        'id_mappings'        => ['RUL-001' => 'lll22222-0000-0000-0000-000000000001'],
                        'status_url'         => '/api/v1/migration/status/880e8400-e29b-41d4-a716-446655440003',
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'rules')]
    public function migrate(): array
    {
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($batch) > $this->getMaxBatchSize()) {
            return ['error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}", 'code' => 422];
        }

        return $this->asyncMigrate();
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_company_id'])) {
            $record['company_id'] = $this->idMappingService->resolve('companies', $record['legacy_company_id'], $contractId) ?? $record['company_id'] ?? null;
            unset($record['legacy_company_id']);
        }

        if (! empty($record['legacy_layout_id'])) {
            $record['layout_id'] = $this->idMappingService->resolve('layouts', $record['legacy_layout_id'], $contractId) ?? $record['layout_id'] ?? null;
            unset($record['legacy_layout_id']);
        }

        $record = $this->resolveContractIdFK($record, $contractId);

        $record['token'] = $this->parseTokenToWeb($record['token'], $record['layout_id'] ?? null);
        $record['cpf_cnpj'] = $record['cpf_cnpj'] ? str_replace(['field_cpfcnpj'], 'field_cpf_cnpj', $record['cpf_cnpj'] ) : "NULLIF(#field_cpf_cnpj#,'') IS NULL";
        $record['client_supplier'] = $record['client_supplier'] ? str_replace(['field_clifor'], 'field_client_supplier', $record['client_supplier'] ) : "NULLIF(#field_client_supplier#,'') IS NULL";
        $record['history'] = $record['history'] ? str_replace(['fnc_semelhante'], 'fnc_similar', $record['history'] ) : "NULLIF(#field_history#,'') IS NULL";
        $record['history'] = $record['history'] ? str_replace(['field_historico'], 'field_history', $record['history'] ) : "NULLIF(#field_history#,'') IS NULL";
        $record['bank'] = $record['bank'] ? str_replace(['field_banco'], 'field_bank', $record['bank'] ) : "NULLIF(#field_bank#,'') IS NULL";
        $record['filial'] = $record['filial'] ? str_replace(['field_filial'], 'field_filial', $record['filial'] ) : "NULLIF(#field_filial#,'') IS NULL";
        $record['additional_information'] = $record['additional_information'] ? str_replace(['field_infadicional'], 'field_additional_information', $record['additional_information'] ) : "NULLIF(#field_additional_information#,'') IS NULL";
        $record['additional_information_3'] = $record['additional_information_3'] ? str_replace(['field_infadicional_compl'], 'field_additional_information_3', $record['additional_information_3'] ) : "NULLIF(#field_additional_information_3#,'') IS NULL";

        return $record;
    }

    private function parseTokenToWeb(string $legacyToken, $layout_id): string
    {
        // Conversão de token legado para formato do sistema web
        // token de (11,1,0,1,1,0,0,0,0) para (a144e3c6-72e8-43a4-b5da-840ca3b55393,t,f,f,t,t,f,f,f,f,f)

        if (empty($legacyToken)) {
            return '';
        }
        // remover parentesses
        $removed = str_replace(['(', ')'], '', $legacyToken);
        // separar por vírgula
        $parts = explode(',', $removed);
        // mapear para o formato web
        $webToken = sprintf(
            '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
            $layout_id,
            $parts[1] === '0' ? 'f' : 't',
            $parts[2] === '0' ? 'f' : 't',
            $parts[3] === '0' ? 'f' : 't',
            $parts[4] === '0' ? 'f' : 't',
            $parts[5] === '0' ? 'f' : 't',
            $parts[6] === '0' ? 'f' : 't',
            $parts[7] === '0' ? 'f' : 't',
            'f',
            $parts[8] === '0' ? 'f' : 't',
            'f'
        );
        return $webToken;
    }
}
