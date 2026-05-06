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
 * Source legada → `confrontation_records`. FKs: confrontations, import_records, imports.
 *
 * Volume alto: chunk_size 2000, uuid7, sem normalize_strings.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class ConfrontationRecordSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'confrontation_records';
    }

    public function targetTable(): string
    {
        return 'confrontation_records';
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

    public function useCopy(): bool
    {
        return $this->copyEnabled(true);
    }

    public function fkMap(): array
    {
        return [
            'legacy_confrontation_id' => 'confrontations',
        ];
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(*) AS count
            FROM confrontos_itens
            WHERE fk_layoutimp <> 0
              AND created_at > NOW() - INTERVAL '60 days'
        SQL;
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                pk                                          AS legacy_id,
                fk_confrontos                               AS legacy_confrontation_id,
                (ABS(valor) = valor_vinculado)              AS conciliated,
                "order"                                     AS order_number,
                "data"                                      AS "date",
                fk_layoutimp                                AS layout_code,
                numdocumento                                AS num_doc,
                cd                                          AS debit_credit,
                valor                                       AS "value",
                historico                                   AS history,
                fornecedor                                  AS client_supplier,
                banco                                       AS bank,
                filial,
                parcela                                     AS parcel,
                cpfcnpj_forn                                AS cpf_cnpj,
                infadicional                                AS additional_information,
                infadicional3                               AS additional_information_3,
                complemento                                 AS complement,
                tipo                                        AS records_origin,
                created_at,
                valor_vinculado                             AS conciliated_value
            FROM confrontos_itens
            WHERE fk_layoutimp <> 0
              AND created_at > NOW() - INTERVAL '60 days'
              AND pk > COALESCE(NULLIF(:last_id, '')::BIGINT, 0)
            ORDER BY pk ASC
            LIMIT :limit
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'date' => 'required|date',
            'layout_code' => 'required|string|max:5',
            'debit_credit' => 'required|in:D,C',
            'value' => 'required|numeric',
            'records_origin' => 'nullable|in:F,B',
        ];
    }
}
