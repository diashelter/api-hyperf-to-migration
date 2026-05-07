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
 * Source legada → `companies`. FKs: contracts, plans, rules_sharings.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class CompanySource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'companies';
    }

    public function targetTable(): string
    {
        return 'companies';
    }

    public function fkMap(): array
    {
        return [
            'legacy_contract_id' => 'contracts',
            'legacy_plan_id' => 'plans',
            'legacy_rules_sharing_id' => 'rules_sharings',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                empresas.pk                                         AS legacy_id,
                pk                                                  AS code,
                codigoexterno                                       AS external_code,
                razaosocial                                         AS corporate_name,
                REGEXP_REPLACE(cnpj, '[^0-9]', '', 'g')             AS cpf_cnpj,
                telefone                                            AS phone,
                endereco                                            AS street,
                numero                                              AS "number",
                complemento                                         AS complement,
                cidade                                              AS city,
                cep                                                 AS zipcode,
                uf                                                  AS "state",
                ramoatividade                                       AS activity_branch,
                celular                                             AS phone_cell,
                email                                               AS email,
                CURRENT_DATABASE()                                  AS legacy_contract_id,
                CASE WHEN fk_pcontasconc = 0 THEN NULL
                     ELSE fk_pcontasconc END                        AS legacy_plan_id,
                CASE WHEN fk_plano_contas = 0 THEN NULL
                     ELSE fk_plano_contas END                       AS legacy_rules_sharing_id,
                INITCAP(tributacao)                                 AS tax_regime,
                CASE utilizaparticipante WHEN 1 THEN true
                     ELSE false END                                 AS use_participant,
                COALESCE(hab_centrocusto, FALSE)                    AS use_cost_center,
                COALESCE(auto_pessoa, FALSE)                        AS use_auto_register_of_people,
                COALESCE(auto_pessoa_wizard, FALSE)                 AS use_auto_register_of_people_in_rules_accounting
            FROM empresas
            WHERE pk <> 0
            ORDER BY pk
        SQL;
    }

    public function countSql(): ?string
    {
        return 'SELECT COUNT(*) AS count FROM empresas WHERE pk <> 0';
    }

    public function validationRules(): array
    {
        return [
            'code' => 'required|int',
            'cpf_cnpj' => 'required|string|size:14',
            'corporate_name' => 'nullable|string|max:255',
            'tax_regime' => 'required|in:Lucro Real,Lucro Presumido,Simples Nacional,Outros',
        ];
    }
}
