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
 * Source legada → `imports`. FKs: contracts, users, companies, company_layout.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class ImportSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'imports';
    }

    public function targetTable(): string
    {
        return 'imports';
    }

    public function fkMap(): array
    {
        return [
            'legacy_contract_id' => 'contracts',
            'legacy_user_id' => 'users',
            'legacy_company_id' => 'companies',
            'legacy_company_layout_id' => 'company_layout',
        ];
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(*) AS count FROM (
                SELECT 1
                FROM importacao
                JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
                WHERE fk_layout <> 0
                GROUP BY importacao.fk_empresa, fk_layoutempresa
                HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
            ) AS sub
        SQL;
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                'IMP-' || fk_layoutempresa                      AS legacy_id,
                (
                    SELECT pk
                    FROM usuarios
                    WHERE administrador = 1 AND pk <> 1
                    LIMIT 1
                )                                               AS legacy_user_id,
                importacao.fk_empresa                           AS legacy_company_id,
                'completed'                                     AS "status",
                CURRENT_DATABASE()                              AS legacy_contract_id,
                fk_layoutempresa                                AS legacy_company_layout_id,
                layout_empresa.saldo_anterior                   AS previous_balance,
                COUNT(fk_layoutempresa) > 10000                 AS is_big_import,
                1                                               AS total_files
            FROM importacao
            JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
            WHERE fk_layout <> 0
            GROUP BY importacao.fk_empresa, fk_layoutempresa, layout_empresa.saldo_anterior
            HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
            ORDER BY fk_layoutempresa
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'total_files' => 'required|integer|min:1',
            'initial_period' => 'nullable|date',
            'final_period' => 'nullable|date',
            'previous_balance' => 'nullable|numeric',
        ];
    }
}
