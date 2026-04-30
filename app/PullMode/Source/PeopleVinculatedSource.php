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
 * Source legada → `people_vinculated`. FKs: peoples, companies, rules_sharings.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class PeopleVinculatedSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'people_vinculated';
    }

    public function targetTable(): string
    {
        return 'people_vinculated';
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function hasContractId(): bool
    {
        return false;
    }

    public function fkMap(): array
    {
        return [
            'legacy_people_id' => 'peoples',
            'legacy_company_id' => 'companies',
            'legacy_rules_sharing_id' => 'rules_sharings',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                id                      AS legacy_id,
                fk_pessoa               AS legacy_people_id,
                fk_empresa              AS legacy_company_id,
                fk_compartilhamento     AS legacy_rules_sharing_id,
                conta_deb               AS debit_account,
                conta_cred              AS credit_account,
                participante            AS participant
            FROM pessoas_vinculo
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'debit_account' => 'nullable|string|max:10',
            'credit_account' => 'nullable|string|max:10',
            'participant' => 'nullable|string|max:100',
            'vinculated_name' => 'nullable|string|max:150',
        ];
    }
}
