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
            WITH scoped_confrontos AS (
                SELECT *
                FROM confrontos
                WHERE confrontos.created_at > NOW() - INTERVAL '60 days'
                  AND confrontos.pk > COALESCE(
                      CAST(NULLIF(:last_id, '') AS UUID),
                      '00000000-0000-0000-0000-000000000000'::UUID
                    )
                ORDER BY confrontos.pk ASC
                LIMIT :limit
            ),
            rec_fin AS (
                SELECT
                    confrontos_itens.fk_confrontos,
                    COUNT(DISTINCT confrontos_itens.pk) AS entries_count,
                    STRING_AGG(DISTINCT confrontos_itens.fk_layoutimp::text, ',' ORDER BY confrontos_itens.fk_layoutimp::text) AS layouts
                FROM confrontos_itens
                JOIN scoped_confrontos ON scoped_confrontos.pk = confrontos_itens.fk_confrontos
                WHERE confrontos_itens.tipo = 'F'
                GROUP BY confrontos_itens.fk_confrontos
            ),
            rec_ban AS (
                SELECT
                    confrontos_itens.fk_confrontos,
                    COUNT(DISTINCT confrontos_itens.pk) AS entries_count,
                    STRING_AGG(DISTINCT confrontos_itens.fk_layoutimp::text, ',' ORDER BY confrontos_itens.fk_layoutimp::text) AS layouts
                FROM confrontos_itens
                JOIN scoped_confrontos ON scoped_confrontos.pk = confrontos_itens.fk_confrontos
                WHERE confrontos_itens.tipo = 'B'
                GROUP BY confrontos_itens.fk_confrontos
            )
            SELECT
                scoped_confrontos.pk                   AS legacy_id,
                scoped_confrontos.fk_empresa           AS legacy_company_id,
                usuarios.pk                     AS legacy_user_create_id,
                descricao                       AS "description",
                criado_por                      AS user_create,
                empresas.fantasia               AS company_name,
                empresas.cnpj                   AS company_cnpj,
                check_data                      AS consider_date,
                check_cd                        AS consider_debit_credit,
                check_numdocumento              AS consider_document,
                ignorar_duplicados              AS ignore_equals,
                scoped_confrontos.created_at,
                CASE 
                    WHEN scoped_confrontos.fk_layout_empresa IS NOT NULL THEN 'completed' 
                    ELSE 'pending'
                END                             AS "status",
                scoped_confrontos.fk_layout_empresa    AS finished_on,
                rec_fin.layouts || ' <=> ' || rec_ban.layouts AS layouts,
                rec_fin.entries_count || ' / ' || rec_ban.entries_count AS entries
            FROM scoped_confrontos
            JOIN rec_fin ON scoped_confrontos.pk = rec_fin.fk_confrontos
            JOIN rec_ban ON scoped_confrontos.pk = rec_ban.fk_confrontos
            JOIN empresas ON empresas.pk = scoped_confrontos.fk_empresa
            LEFT JOIN usuarios ON usuarios.nome = criado_por
            ORDER BY scoped_confrontos.pk ASC
        SQL;
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(*) AS count
            FROM confrontos
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
