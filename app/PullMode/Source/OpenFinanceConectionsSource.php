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
 * Source legada → `company_layout`. FKs: companies, layouts.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class OpenFinanceConectionsSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'open_finance_connections';
    }

    public function targetTable(): string
    {
        return 'open_finance_connections';
    }

    public function fkMap(): array
    {
        return [
            'legacy_company_id' => 'companies',
        ];
    }

    public function hasContractId(): bool
    {
        return true;
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                itemid AS legacy_id,
                itemid AS id,
                fk_empresa AS legacy_company_id
            FROM layout_empresa
            JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
            WHERE fk_empresa <> 0
                AND fk_layoutimp <> 0
                AND layout_empresa.hab_belvo = true
                AND itemid IS NOT NULL
                AND layout_empresa.itemid::UUID > COALESCE(
                    CAST(NULLIF(:last_id, '') AS UUID),
                    '00000000-0000-0000-0000-000000000000'::UUID
                )
            GROUP BY 1,2,3
            ORDER BY layout_empresa.itemid
            LIMIT :limit
        SQL;
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(itemid) AS count
            FROM layout_empresa
            JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
            WHERE fk_empresa <> 0
                AND fk_layoutimp <> 0
                AND layout_empresa.hab_belvo = true
                AND itemid IS NOT NULL
        SQL;
    }
}
