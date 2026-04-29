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
class CompanyLayoutSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'company_layout';
    }

    public function targetTable(): string
    {
        return 'company_layout';
    }

    public function fkMap(): array
    {
        return [
            'legacy_company_id' => 'companies',
            'legacy_layout_imp' => 'layouts',
            'legacy_layout_exp' => 'layouts',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                layout_empresa.pk       AS legacy_id,
                fk_empresa              AS legacy_company_id,
                fk_layoutimp            AS legacy_layout_imp,
                fk_layoutexp            AS legacy_layout_exp,
                tipo_contab             AS type_accounting,
                conta_cred              AS credit_account,
                conta_deb               AS debit_account,
                COALESCE(conta_fixa_hab, FALSE) AS account_fixed
            FROM layout_empresa
            JOIN layout ON layout.pk = layout_empresa.fk_layoutimp
            WHERE fk_empresa <> 0
              AND fk_layoutimp <> 0
            ORDER BY layout_empresa.pk
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'type_accounting' => 'nullable|in:DCH,DC,LA',
            'credit_account' => 'nullable|string|max:20',
            'debit_account' => 'nullable|string|max:20',
            'account_fixed' => 'nullable|boolean',
        ];
    }
}
