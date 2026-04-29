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
 * Source legada → `confrontations`. FKs: contracts, companies.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class ConfrontationSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'confrontations';
    }

    public function targetTable(): string
    {
        return 'confrontations';
    }

    public function fkMap(): array
    {
        return [
            'legacy_company_id' => 'companies',
            'legacy_user_create_id' => 'users',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                confrontos.pk               AS legacy_id,
                fk_empresa                  AS legacy_company_id,
                usuarios.pk                 AS legacy_user_create_id,
                descricao                   AS "description",
                criado_por                  AS user_create,
                empresas.fantasia           AS company_name,
                empresas.cnpj               AS company_cnpj,
                check_data                  AS consider_date,
                check_cd                    AS consider_debit_credit,
                check_numdocumento          AS consider_document,
                ignorar_duplicados          AS ignore_equals,
                created_at
            FROM confrontos
            JOIN empresas ON empresas.pk = confrontos.fk_empresa
            LEFT JOIN usuarios ON usuarios.nome = criado_por
            WHERE created_at > NOW() - INTERVAL '60 days'
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'description' => 'required|string|max:255',
            'user_create_id' => 'required|uuid',
            'user_create' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'company_cnpj' => 'nullable|string|max:14',
        ];
    }
}
