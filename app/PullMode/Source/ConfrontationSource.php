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
                confrontos.pk                   AS legacy_id,
                confrontos.fk_empresa           AS legacy_company_id,
                usuarios.pk                     AS legacy_user_create_id,
                descricao                       AS "description",
                criado_por                      AS user_create,
                empresas.fantasia               AS company_name,
                empresas.cnpj                   AS company_cnpj,
                check_data                      AS consider_date,
                check_cd                        AS consider_debit_credit,
                check_numdocumento              AS consider_document,
                ignorar_duplicados              AS ignore_equals,
                confrontos.created_at,
                CASE 
                    WHEN confrontos.fk_layout_empresa IS NOT NULL THEN 'completed' 
                    ELSE 'pending'
                END                             AS "status",
                confrontos.fk_layout_empresa    AS finished_on,
                rec_fin.fk_layoutimp ||
                ' <=> ' ||
                rec_ban.fk_layoutimp            AS layouts,
                COUNT(DISTINCT rec_fin.pk) 
                || ' / ' 
                || COUNT(DISTINCT rec_ban.pk)   AS entries					 
            FROM confrontos
            JOIN confrontos_itens rec_fin ON confrontos.pk = rec_fin.fk_confrontos AND rec_fin.tipo = 'F'
            JOIN confrontos_itens rec_ban ON confrontos.pk = rec_ban.fk_confrontos AND rec_ban.tipo = 'B'
            JOIN empresas ON empresas.pk = confrontos.fk_empresa
            LEFT JOIN usuarios ON usuarios.nome = criado_por
            WHERE confrontos.created_at > NOW() - INTERVAL '60 days'
              AND confrontos.pk > COALESCE(
                  CAST(NULLIF(:last_id, '') AS UUID),
                  '00000000-0000-0000-0000-000000000000'::UUID
                )
            GROUP BY 1,2,3,4,5,6,7,8,9,10,11,12,13,14,15
            ORDER BY confrontos.pk ASC
            LIMIT :limit
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
