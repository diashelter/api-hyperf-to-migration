<?php

declare(strict_types=1);

namespace App\Controller\Migration;

use App\Controller\AbstractMigrationController;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;
use Ramsey\Uuid\Uuid;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class ImportRecordMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'import_records';
    }

    protected function getEntity(): string
    {
        return 'import_records';
    }

    protected function getMaxBatchSize(): int
    {
        return 2000;
    }

    protected function getConnection(): string
    {
        return 'conciliador_web';
    }

    protected function validationRules(): array
    {
        return [
            'date'                        => 'required|date_format:Y-m-d',
            'history'                     => 'required|string',
            'value'                       => 'nullable|numeric',
            'debit_credit'                => 'nullable|in:D,C',
            'num_doc'                     => 'nullable|string|max:255',
            'client_supplier'             => 'nullable|string|max:255',
            'bank'                        => 'nullable|string|max:255',
            'filial'                      => 'nullable|string|max:10',
            'cpf_cnpj'                    => 'nullable|string|max:14',
            'debit_account'               => 'nullable|string|max:15',
            'credit_account'              => 'nullable|string|max:15',
            'complement'                  => 'nullable|string',
            'additional_information'      => 'nullable|string',
            'additional_information_2'    => 'nullable|string|max:255',
            'additional_information_3'    => 'nullable|string|max:255',
            'parcel'                      => 'nullable|string|max:3',
            'third_party_participant'     => 'nullable|string|max:150',
            'due_date'                    => 'nullable|date',
            'refund_values'               => 'nullable|numeric',
            'pis_value'                   => 'nullable|numeric',
            'cofins_value'                => 'nullable|numeric',
            'csll_value'                  => 'nullable|numeric',
            'irrf_value'                  => 'nullable|numeric',
        ];
    }

    /**
     * import_records usa UUID v7 (time-ordered) para performance de índice no PostgreSQL.
     * UUID v4 aleatório causa fragmentação severa em tabelas com 1M+ registros.
     */
    protected function generateId(): string
    {
        return Uuid::uuid7()->toString();
    }

    #[OA\Post(
        path: '/api/v1/migration/import-records',
        summary: 'Migrar registros de importação (async)',
        description: <<<'DESC'
Insere registros de importação em lote via processamento paralelo com coroutines Swoole.
Fase 9c — MAIOR VOLUME (1M+). Depende de imports, import_sessions.

**Transformações automáticas:**
- `debit_value` + `credit_value` → `value` (absoluto) + `debit_credit` (D/C)
- `additional_information_1` → `additional_information` (nome correto no banco)
- `debit_account` e `credit_account` truncados para 15 chars sem espaços

**Finalização pós-insert (último batch):**
- Envie `finalize: true` no último batch de cada import_session para:
  1. Atualizar `import_sessions.status = 'completed'`
  2. Atualizar `imports.status = 'completed'` + calcular `is_big_import`

Max batch: 2000. Rate limit: 30 req/min.
FK legados: legacy_import_id, legacy_import_session_id.
DESC,
        tags: ['Migration - Async'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['batch'],
                properties: [
                    new OA\Property(property: 'batch', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'finalize', type: 'boolean', nullable: true, description: 'Se true, atualiza status de import_sessions e imports após o insert (usar no último batch de cada sessão)', example: false),
                ],
                example: [
                    'batch' => [
                        [
                            'legacy_id'                    => 'IR-0001',
                            'date'                         => '2024-01-10',
                            'history'                      => 'Pagamento fornecedor',
                            'debit_value'                  => 1500.00,
                            'credit_value'                 => null,
                            'num_doc'                      => 'NF-001234',
                            'client_supplier'              => 'Fornecedor ABC',
                            'cpf_cnpj'                     => '11222333000181',
                            'debit_account'                => '41001',
                            'credit_account'               => '11001',
                            'legacy_import_id'             => 'IMP-001',
                            'legacy_import_session_id'     => 'IS-001',
                        ],
                    ],
                    'finalize' => false,
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
                        'migration_batch_id' => '770e8400-e29b-41d4-a716-446655440002',
                        'entity'             => 'import_records',
                        'total_received'     => 1,
                        'status'             => 'completed',
                        'inserted'           => 1,
                        'failed'             => 0,
                        'errors'             => null,
                        'id_mappings'        => ['IR-0001' => 'kkk11111-0000-0000-0000-000000000001'],
                        'status_url'         => '/api/v1/migration/status/770e8400-e29b-41d4-a716-446655440002',
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'import-records')]
    public function migrate(): array
    {
        $batch = $this->request->input('batch', []);
        $finalize = (bool) $this->request->input('finalize', false);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($batch) > $this->getMaxBatchSize()) {
            return ['error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}", 'code' => 422];
        }

        $result = $this->asyncMigrate();

        if ($finalize && empty($result['error'])) {
            $this->finalizeImportSessions($batch);
        }

        return $result;
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        // --- Resolução de FKs legadas ---
        if (! empty($record['legacy_import_id'])) {
            $record['import_id'] = $this->idMappingService->resolve('imports', $record['legacy_import_id'], $contractId) ?? $record['import_id'] ?? null;
            unset($record['legacy_import_id']);
        }

        if (! empty($record['legacy_import_session_id'])) {
            $record['import_session_id'] = $this->idMappingService->resolve('import_sessions', $record['legacy_import_session_id'], $contractId) ?? $record['import_session_id'] ?? null;
            unset($record['legacy_import_session_id']);
        }

        // --- Mapeamento de nomes de campos (sistema legado → banco) ---

        // additional_information_1 → additional_information (campo original não tem sufixo _1)
        if (isset($record['additional_information_1']) && ! isset($record['additional_information'])) {
            $record['additional_information'] = $record['additional_information_1'];
        }
        unset($record['additional_information_1']);

        // debit_value + credit_value → value + debit_credit
        // O banco só tem `value` (sempre positivo) e `debit_credit` (D ou C)
        if (isset($record['debit_value']) || isset($record['credit_value'])) {
            $debitValue = isset($record['debit_value']) ? (float) $record['debit_value'] : null;
            $creditValue = isset($record['credit_value']) ? (float) $record['credit_value'] : null;

            if ($debitValue !== null && $debitValue > 0) {
                $record['value'] = $debitValue;
                $record['debit_credit'] = 'D';
            } elseif ($creditValue !== null && $creditValue > 0) {
                $record['value'] = $creditValue;
                $record['debit_credit'] = 'C';
            }

            unset($record['debit_value'], $record['credit_value']);
        }

        // --- Mutators do Model (não aplicados em DB::table()->insert()) ---

        // debit_account: truncar 15 chars + remover espaços
        if (isset($record['debit_account']) && $record['debit_account'] !== null) {
            $record['debit_account'] = substr(str_replace(' ', '', (string) $record['debit_account']), 0, 15);
        }

        // credit_account: truncar 15 chars + remover espaços
        if (isset($record['credit_account']) && $record['credit_account'] !== null) {
            $record['credit_account'] = substr(str_replace(' ', '', (string) $record['credit_account']), 0, 15);
        }

        return $record;
    }

    /**
     * Finaliza as import_sessions e imports após o último batch de registros.
     * Equivale aos passos 9.4 / checklist 14e-14f do MIGRATION_FLOW.md.
     *
     * Nota: CleanImportRecordsForbiddenCharactersService (passo 14d) deve ser
     * chamado separadamente na aplicação Conciliador Web após a migração completa.
     */
    private function finalizeImportSessions(array $batch): void
    {
        $importSessionIds = array_values(array_unique(array_filter(array_column($batch, 'import_session_id'))));
        $importIds = array_values(array_unique(array_filter(array_column($batch, 'import_id'))));

        $db = Db::connection($this->getConnection());

        if (! empty($importSessionIds)) {
            $db->table('import_sessions')
                ->whereIn('id', $importSessionIds)
                ->update(['status' => 'completed', 'updated_at' => date('Y-m-d H:i:s')]);
        }

        if (! empty($importIds)) {
            foreach ($importIds as $importId) {
                $count = $db->table('import_records')
                    ->where('import_id', $importId)
                    ->count();

                $db->table('imports')
                    ->where('id', $importId)
                    ->update([
                        'status' => 'completed',
                        'is_big_import' => $count > 10000,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
        }
    }
}
