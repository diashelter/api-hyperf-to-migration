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

use Hyperf\DbConnection\Db;

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

    public function paginationKey(): string
    {
        return 'pagination_cursor';
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
            'legacy_id_rule' => 'rules',
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
                importacao.fk_layoutempresa || '|' || importacao.uuid AS pagination_cursor,
                'IMP-' || importacao.fk_layoutempresa              AS legacy_import_id,
                'IS-' || importacao.fk_layoutempresa               AS legacy_import_session_id,
                importacao.fk_regra                                AS legacy_id_rule,
                COALESCE("data", '1976-01-01')                   AS date,
                importacao.historico                               AS history,
                CASE
                    WHEN ABS(importacao.valor) < 10000000000000 THEN importacao.valor
                    ELSE 9999999999999
                END                                                AS value,
                CASE cd
                    WHEN 'C' THEN 'C'
                    ELSE 'D' END                                   AS debit_credit,
                importacao.numdocumento                            AS num_doc,
                importacao.fornecedor                              AS client_supplier,
                importacao.banco                                   AS bank,
                importacao.filial                                  AS filial,
                REGEXP_REPLACE(importacao.cpfcnpj_forn, '[^0-9]', '', 'g') AS cpf_cnpj,
                importacao.con_iddebito                            AS debit_account,
                importacao.con_idcredito                           AS credit_account,
                importacao.complemento                             AS complement,
                importacao.datavencimento                          AS due_date,
                LEFT(importacao.parcela, 25)                       AS parcel,
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
                END                                     AS conciliation_status,
                ROW_NUMBER() OVER (
                    PARTITION BY fk_layoutempresa
                    ORDER BY uuid
                ) AS order_number
            FROM importacao
            JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
            WHERE importacao.fk_layout <> 0
            AND importacao.inclusao > NOW() - INTERVAL '60 days'
              AND (importacao.fk_layoutempresa, importacao.uuid) > (
                  CAST(:last_layoutempresa AS BIGINT),
                  CAST(:last_uuid AS UUID)
              )
            ORDER BY importacao.fk_layoutempresa ASC, importacao.uuid ASC
            LIMIT :limit
        SQL;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paginate(string $connection, ?string $lastId, int $limit): array
    {
        [$lastLayoutEmpresa, $lastUuid] = $this->parsePaginationCursor($lastId);

        $rows = Db::connection($connection)->select($this->sql(), [
            'last_layoutempresa' => $lastLayoutEmpresa,
            'last_uuid' => $lastUuid,
            'limit' => $limit,
        ]);

        return array_map(static fn ($r) => (array) $r, $rows);
    }

    public function transformRow(array $row, string $contractId): array
    {
        unset($row['pagination_cursor']);

        return $row;
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

    /**
     * @return array{0: int, 1: string}
     */
    private function parsePaginationCursor(?string $lastId): array
    {
        $initial = [0, '00000000-0000-0000-0000-000000000000'];

        if ($lastId === null || trim($lastId) === '') {
            return $initial;
        }

        $parts = explode('|', $lastId, 2);
        if (count($parts) !== 2) {
            return $initial;
        }

        $layoutEmpresa = filter_var($parts[0], FILTER_VALIDATE_INT);
        $uuid = trim($parts[1]);

        if ($layoutEmpresa === false || $layoutEmpresa < 0 || $uuid === '') {
            return $initial;
        }

        return [$layoutEmpresa, $uuid];
    }
}
