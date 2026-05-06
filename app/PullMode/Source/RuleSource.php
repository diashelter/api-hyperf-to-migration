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
 * Source legada → `rules`. FKs: companies, layouts, contracts.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class RuleSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'rules';
    }

    public function targetTable(): string
    {
        return 'rules';
    }

    public function chunkSize(): int
    {
        return 5000;
    }

    public function useCopy(): bool
    {
        return $this->copyEnabled(true);
    }

    public function fkMap(): array
    {
        return [
            'legacy_company_id' => 'companies',
            'legacy_layout_id' => 'layouts',
        ];
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(*) AS count
            FROM regras
            JOIN layout_empresa ON regras.fk_layout_empresa = layout_empresa.pk
            WHERE fk_layoutimp <> 0
        SQL;
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                regras.id                                               AS legacy_id,
                layout_empresa.fk_empresa                              AS legacy_company_id,
                layout_empresa.fk_layoutimp                            AS legacy_layout_id,
                CASE cd WHEN 'C' THEN 'C' ELSE 'D' END                AS debit_credit,
                cpfcnpj                                                AS cpf_cnpj,
                clifor                                                 AS client_supplier,
                historico                                              AS history,
                banco                                                  AS bank,
                filial                                                 AS filial,
                infadicional                                           AS additional_information,
                infadicional_compl                                     AS additional_information_3,
                token                                                  AS token,
                exclusivo                                              AS "exclusive",
                idhistorico                                            AS id_history,
                iddebito                                               AS id_debit,
                idcredito                                              AS id_credit,
                historicoexp                                           AS id_history_exp,
                idcccredito                                            AS id_cc_credit,
                idccdebito                                             AS id_cc_debit,
                reprocessar                                            AS reprocess,
                invalida                                               AS invalid,
                historico_imp                                          AS history_value,
                cpfcnpj_imp                                            AS cpf_cnpj_value,
                clifor_imp                                             AS client_supplier_value,
                banco_imp                                              AS bank_value,
                filial_imp                                             AS filial_value,
                infadicional_imp                                       AS additional_information_value,
                infadicional_compl_imp                                 AS additional_information_3_value,
                idparticipante                                         AS third_party_participant
            FROM regras
            JOIN layout_empresa ON regras.fk_layout_empresa = layout_empresa.pk
            WHERE fk_layoutimp <> 0
              AND regras.id > COALESCE(
                CAST(NULLIF(:last_id, '') AS UUID),
                '00000000-0000-0000-0000-000000000000'::UUID
              )
            ORDER BY regras.id ASC
            LIMIT :limit
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'debit_credit' => 'nullable|in:D,C',
            'cpf_cnpj' => 'nullable|string',
            'client_supplier' => 'nullable|string',
            'history' => 'nullable|string',
            'exclusive' => 'nullable|boolean',
            'reprocess' => 'nullable|boolean',
            'invalid' => 'nullable|boolean',
            'automatic_launch' => 'nullable|boolean',
        ];
    }
}
