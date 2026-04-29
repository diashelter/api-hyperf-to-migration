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
 * Source legada → `layouts`. Auto-referência via `reference_layout`.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class LayoutSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'layouts';
    }

    public function targetTable(): string
    {
        return 'layouts';
    }

    public function fkMap(): array
    {
        return [
            'legacy_contract_id' => 'contracts',
            'reference_layout' => 'layouts',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT DISTINCT
                layout.pk                                                       AS code,
                layout.pk                                                       AS legacy_id,
                nome                                                            AS name,
                CASE setor WHEN 'C' THEN 'Contábil' ELSE 'Fiscal' END          AS sector,
                COALESCE(NULLIF(formato, ''), 'PDF')                            AS format,
                COALESCE(INITCAP(tipomovimento), 'Ambos')                       AS movement_type,
                COALESCE(linha, 1)                                              AS start_row,

                numdocumento                                                    AS num_doc_column,
                parcela                                                         AS parcel_separator,
                "data"                                                          AS date_column,
                historico                                                       AS history_column,
                historicocontinua                                               AS history_2_lines_column,
                fornecedor                                                      AS client_supplier_column,
                cpfcnpj_forn                                                    AS cpf_cnpj_column,
                dc                                                              AS debit_credit_column,
                datavencimento                                                  AS due_date_column,
                banco                                                           AS bank_column,
                participante                                                    AS third_party_participant_column,
                infadicional                                                    AS additional_information_column,
                LEFT(infadicional2::text, 10)                                    AS complement_column,
                contadeb                                                        AS debit_account_column,
                contacred                                                       AS credit_account_column,
                filial                                                          AS filial_column,
                infadicional2                                                   AS additional_information_2_column,
                infadicional3                                                   AS additional_information_3_column,
                ignorar                                                         AS ignore_history,
                layout.obs                                                      AS comments,

                valor                                                           AS debit_value_column,
                valor_debito                                                    AS credit_value_column,
                juros                                                           AS interest_column,
                multa                                                           AS fine_column,
                desconto                                                        AS discounts_column,
                valordevolucao                                                  AS refund_values_column,
                valoroutros                                                     AS other_values_column,

                CASE dataanterior           WHEN 1 THEN TRUE ELSE FALSE END     AS consider_previous_date,
                CASE fornecedoranterior     WHEN 1 THEN TRUE ELSE FALSE END     AS consider_previous_client_supplier,
                CASE inforadicionalanterior WHEN 1 THEN TRUE ELSE FALSE END     AS consider_previous_adicional_information,
                CASE historicoanterior      WHEN 1 THEN TRUE ELSE FALSE END     AS consider_previous_history,
                CASE filialanterior         WHEN 1 THEN TRUE ELSE FALSE END     AS consider_previous_filial,
                CASE bancoanterior          WHEN 1 THEN TRUE ELSE FALSE END     AS consider_previous_bank,
                CASE historicosomenteprimeiralinha WHEN 1 THEN TRUE ELSE FALSE END AS consider_history_only_fline,

                CASE WHEN fk_layout_mestre IS NOT NULL THEN true ELSE false END AS is_default_layout,
                CASE WHEN fk_layout_mestre IS NULL     THEN true ELSE false END AS is_layout_master,
                fk_layout_mestre                                                AS reference_layout,

                CASE possui_cd_cc     WHEN 1 THEN TRUE ELSE FALSE END           AS has_d_c_account,
                CASE cpfcnpj_to_part  WHEN 1 THEN TRUE ELSE FALSE END           AS copy_third_party_participant_doc,
                CASE solicitarmes     WHEN 1 THEN TRUE ELSE FALSE END           AS request_month,
                CASE solicitarano     WHEN 1 THEN TRUE ELSE FALSE END           AS request_year,
                CASE solicitar_serie  WHEN 1 THEN TRUE ELSE FALSE END           AS request_serie,
                CASE solicitar_especie WHEN 1 THEN TRUE ELSE FALSE END          AS request_especie,
                CURRENT_DATABASE()                                              AS legacy_contract_id,

                CASE agruparcd                    WHEN 1 THEN TRUE ELSE FALSE END AS consider_dc_for_accounting_rules,
                CASE agrupahistorico              WHEN 1 THEN TRUE ELSE FALSE END AS consider_history_for_accounting_rules,
                CASE agruparcpfcnpj               WHEN 1 THEN TRUE ELSE FALSE END AS consider_participant_doc_for_accounting_rules,
                CASE agruparfornecedorconciliacao WHEN 1 THEN TRUE ELSE FALSE END AS consider_participant_for_accounting_rules,
                CASE agruparbanco                 WHEN 1 THEN TRUE ELSE FALSE END AS consider_bank_for_accounting_rules,
                CASE agruparfilial                WHEN 1 THEN TRUE ELSE FALSE END AS consider_filial_for_accounting_rules,
                CASE agruparinfadicional          WHEN 1 THEN TRUE ELSE FALSE END AS consider_additional_info_for_accounting_rules,
                FALSE                                                           AS consider_additional_info_2_for_accounting_rules,
                CASE agruparinfadicional3         WHEN 1 THEN TRUE ELSE FALSE END AS consider_additional_info_3_for_accounting_rules,
                FALSE                                                           AS consider_rule_extra_for_accounting_rules,

                CASE numdocnohistorico      WHEN 1 THEN TRUE ELSE FALSE END     AS include_document_number_in_history_when_export,
                CASE historicoremoverquebra WHEN 1 THEN TRUE ELSE FALSE END     AS remove_line_break_in_history_when_export,
                CASE mantervalor            WHEN 1 THEN TRUE ELSE FALSE END     AS keep_original_values_when_import,
                parcela_padrao                                                  AS parcel_separator_when_import,
                CASE WHEN diaadd BETWEEN 0 AND 999 THEN diaadd::text ELSE NULL END AS day_to_add_when_import,

                CASE alterar_numerodocumento WHEN 1 THEN TRUE ELSE FALSE END    AS show_document_number,
                CASE mostrarparcela         WHEN 1 THEN TRUE ELSE FALSE END     AS show_parcel,
                CASE naoexibir              WHEN 1 THEN TRUE ELSE FALSE END     AS hide_in_list,
                CASE mostrarfornecedor      WHEN 1 THEN TRUE ELSE FALSE END     AS show_participant,
                CASE mostrarbanco           WHEN 1 THEN TRUE ELSE FALSE END     AS show_bank,
                CASE editargrid             WHEN 1 THEN TRUE ELSE FALSE END     AS enable_edit_in_grid,
                CASE mostrarfilial          WHEN 1 THEN TRUE ELSE FALSE END     AS show_filial,
                CASE mostrar_compl          WHEN 1 THEN TRUE ELSE FALSE END     AS show_complement,
                CASE mostrar_infoadd        WHEN 1 THEN TRUE ELSE FALSE END     AS show_additional_info_1,
                FALSE                                                           AS show_additional_info_2,
                CASE mostrar_infoadd3       WHEN 1 THEN TRUE ELSE FALSE END     AS show_additional_info_3,
                CASE alterar_numerodocumento WHEN 1 THEN TRUE ELSE FALSE END    AS change_document_number,
                CASE invertersinal          WHEN 1 THEN TRUE ELSE FALSE END     AS invert_sign,
                CASE imp_lnc_bloqueado      WHEN 1 THEN TRUE ELSE FALSE END     AS import_blocked_entries,
                CASE extrato                WHEN 1 THEN TRUE ELSE FALSE END     AS bank_statement,
                CASE parcela_padrao         WHEN 1 THEN TRUE ELSE FALSE END     AS bring_1_as_default_parcel,
                COALESCE(confronto_banco, FALSE)                                AS confront_consider_banks,

                NULL                                                            AS layouts_admin_id,
                NULL                                                            AS tariff_column,
                NULL                                                            AS addition_column,
                FALSE                                                           AS request_password,
                FALSE                                                           AS request_input,
                FALSE                                                           AS request_input_title,
                FALSE                                                           AS participant_marking_enabled,

                NULL                                                            AS pis_column,
                NULL                                                            AS cofins_column,
                NULL                                                            AS csll_column,
                NULL                                                            AS irrf_column,
                NULL                                                            AS pis_cosirf_column,
                NULL                                                            AS cofins_cosirf_column,
                NULL                                                            AS csll_cosirf_column,
                NULL                                                            AS irpj_cosirf_column,
                NULL                                                            AS irrfp_column
            FROM public.layout
            JOIN layout_empresa ON layout.pk = layout_empresa.fk_layoutimp
            WHERE visivel = 1
              AND tipo = 'IMP'
            ORDER BY layout.pk
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'format' => 'required|string',
            'sector' => 'nullable|in:Contábil,Fiscal',
            'movement_type' => 'required|in:Ambos,Pagar,Receber',
            'start_row' => 'required|integer|min:1',
        ];
    }
}
