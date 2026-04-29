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
 * Source legada → `company_layout_fixed_accounts`. FK: company_layout.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class CompanyLayoutFixedAccountSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'company_layout_fixed_accounts';
    }

    public function targetTable(): string
    {
        return 'company_layout_fixed_accounts';
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function fkMap(): array
    {
        return [
            'legacy_company_layout_id' => 'company_layout',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT 
            'LF-' || pk AS legacy_id,
            pk AS legacy_company_layout_id,
            conta_fixa AS bank_account,
            contas_fixas_modelo

        FROM layout_empresa
        WHERE conta_fixa_hab = true
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'legacy_id' => 'required|string|max:255',
            'legacy_company_layout_id' => 'required|string|max:255',
            'bank_account' => 'nullable|string|max:100',
            'contas_fixas_modelo' => 'required|string',
        ];
    }
}
