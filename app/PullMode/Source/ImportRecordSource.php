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
        return 5000;
    }

    public function idStrategy(): string
    {
        return 'uuid7';
    }

    public function normalizeStrings(): bool
    {
        return false;
    }

    public function hasContractId(): bool
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
            'legacy_import_id' => 'imports',
            'legacy_import_session_id' => 'import_sessions',
        ];
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(*) AS count
            FROM importacao
            JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
            WHERE importacao.fk_layout <> 0
            AND importacao.inclusao > NOW() - INTERVAL '60 days'
        SQL;
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                importacao.uuid                                    AS legacy_id,
                importacao.uuid                                    AS id,
                'IMP-' || importacao.fk_layoutempresa              AS legacy_import_id,
                'IS-' || importacao.fk_layoutempresa               AS legacy_import_session_id,
                importacao.data                                    AS date,
                COALESCE(importacao.historico_novo, importacao.historico) AS history,
                CASE
                    WHEN ABS(importacao.valor) < 10000000000000 THEN importacao.valor
                    ELSE NULL
                END                                                AS value,
                importacao.cd                                      AS debit_credit,
                importacao.numdocumento                            AS num_doc,
                importacao.fornecedor                              AS client_supplier,
                importacao.banco                                   AS bank,
                importacao.filial                                  AS filial,
                REGEXP_REPLACE(importacao.cpfcnpj_forn, '[^0-9]', '', 'g') AS cpf_cnpj,
                importacao.con_iddebito                            AS debit_account,
                importacao.con_idcredito                           AS credit_account,
                importacao.complemento                             AS complement,
                importacao.datavencimento                          AS due_date,
                importacao.parcela                                 AS parcel,
                importacao.con_idhistorico                         AS history_code,
                importacao.historicoexp                            AS accounting_history,
                importacao.infadicional                            AS additional_information,
                importacao.infadicional3                           AS additional_information_3,
                importacao.detalhamento_credito                    AS credit_detail,
                importacao.detalhamento_debito                     AS debit_detail,
                importacao.cc_debito                               AS cc_debit,
                importacao.cc_credito                              AS cc_credit,
                LEFT(importacao.historico_semelhante, 255)         AS similar_history,
                importacao.historico_novo                          AS new_history,
                importacao.con_idparticipante                      AS third_party_participant,
                importacao.con_idparticipante_d                    AS participant_debit,
                importacao.con_idparticipante_cd                   AS participant_credit,
                CASE WHEN COALESCE(importacao.verificado, false) THEN 1 ELSE 0 END AS checked,
                CASE WHEN COALESCE(importacao.exportado, 0) = 1 THEN 1 ELSE 0 END AS was_exported,
                CASE WHEN COALESCE(importacao.desprezado, 0) = 1 THEN 1 ELSE 0 END AS not_considered,
                CASE WHEN COALESCE(importacao.exclusivo, false) THEN 1 ELSE 0 END AS is_exclusived,
                CASE WHEN COALESCE(importacao.multiplo, false) THEN 1 ELSE 0 END AS is_split,
                CASE
                    WHEN importacao.fk_conciliacao IS NOT NULL THEN 1
                    ELSE 0
                END                                     AS is_conciliated,
                CASE importacao.situacao_confronto
                    WHEN 'CO' THEN 'Y'
                    WHEN 'NC' THEN 'N'
                    WHEN 'AF' THEN 'F'
                    ELSE NULL
                END                                     AS conciliation_status
            FROM importacao
            JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
            WHERE importacao.fk_layout <> 0
            AND importacao.inclusao > NOW() - INTERVAL '60 days'
              AND importacao.uuid > COALESCE(
                  CAST(NULLIF(:last_id, '') AS UUID),
                  '00000000-0000-0000-0000-000000000000'::UUID
              )
            ORDER BY importacao.uuid ASC
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
