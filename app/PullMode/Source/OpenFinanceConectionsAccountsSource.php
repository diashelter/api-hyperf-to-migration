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
class OpenFinanceConectionsAccountsSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'open_finance_connection_accounts';
    }

    public function targetTable(): string
    {
        return 'open_finance_connection_accounts';
    }

    public function fkMap(): array
    {
        return [
            'legacy_open_finance_connection_id' => 'open_finance_connections',
            'legacy_company_layout_id'          => 'company_layout',
        ];
    }

    public function hasContractId(): bool
    {
        return false;
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                layout_empresa.pk   AS legacy_company_layout_id,
                itemid 			    AS legacy_open_finance_connection_id,
                account             AS legacy_id,
                account             AS id,
                null                AS updated_at
            FROM layout_empresa
            JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
            WHERE fk_empresa <> 0
                AND fk_layoutimp <> 0
                AND layout_empresa.hab_belvo = true
                AND itemid IS NOT NULL
                AND account IS NOT NULL
                AND account::UUID > COALESCE(
                    CAST(NULLIF(:last_id, '') AS UUID),
                    '00000000-0000-0000-0000-000000000000'::UUID
                )
            ORDER BY account
            LIMIT :limit
        SQL;
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(*) AS count
            FROM layout_empresa
            JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
            WHERE fk_empresa <> 0
                AND fk_layoutimp <> 0
                AND layout_empresa.hab_belvo = true
                AND itemid IS NOT NULL
                AND account IS NOT NULL
        SQL;
    }
}
