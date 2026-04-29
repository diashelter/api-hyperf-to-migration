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
 * Source legada → `peoples`. Sem FK (mas escopada por contract_id do header).
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class PeopleSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'peoples';
    }

    public function targetTable(): string
    {
        return 'peoples';
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function fkMap(): array
    {
        return [
            'legacy_contract_id' => 'contracts',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                id                      AS legacy_id,
                nomerazao               AS corporate_name,
                cpfcnpj                 AS cpf_cnpj,
                CURRENT_DATABASE()      AS legacy_contract_id
            FROM pessoas
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'cpf_cnpj' => 'nullable|string|max:14',
            'corporate_name' => 'required|string|max:100',
        ];
    }
}
