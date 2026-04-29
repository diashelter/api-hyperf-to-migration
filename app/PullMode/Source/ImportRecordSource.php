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

namespace App\PullMode\Source;

/**
 * Source legada → `import_records`. FKs: imports, import_sessions.
 *
 * Volume alto: chunk_size 2000, uuid7 (índice ordenado), sem normalize_strings,
 * sem record-level logging (configurado via MIGRATION_SKIP_LOG_ENTITIES).
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class ImportRecordSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'import_records';
    }

    public function targetTable(): string
    {
        return 'import_records';
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function idStrategy(): string
    {
        return 'uuid7';
    }

    public function normalizeStrings(): bool
    {
        return false;
    }

    public function fkMap(): array
    {
        return [
            'legacy_import_id' => 'imports',
            'legacy_import_session_id' => 'import_sessions',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                id                          AS legacy_id,
                importacao_id               AS legacy_import_id,
                importacao_sessao_id        AS legacy_import_session_id,
                data                        AS date,
                historico                   AS history,
                valor                       AS value,
                debito_credito              AS debit_credit,
                num_doc                     AS num_doc,
                cliente_fornecedor          AS client_supplier,
                banco                       AS bank,
                filial                      AS filial,
                cpf_cnpj                    AS cpf_cnpj,
                conta_debito                AS debit_account,
                conta_credito               AS credit_account,
                complemento                 AS complement
                -- TODO: mapear demais colunas (additional_information, parcel, due_date, taxes...)
            FROM importacao_registro
            WHERE id::text > :last_id
            ORDER BY id ASC
            LIMIT :limit
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'history' => 'required|string',
            'value' => 'nullable|numeric',
            'debit_credit' => 'nullable|in:D,C',
        ];
    }
}
