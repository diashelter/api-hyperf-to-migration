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
            SELECT DISTINCT ON ( REGEXP_REPLACE(cpfcnpj, '[^0-9]', '', 'g')  )
                    pessoas.id AS legacy_id,
                    REGEXP_REPLACE(pessoas.cpfcnpj, '[^0-9]', '', 'g') AS cpf_cnpj,
                        pessoas.nomerazao AS corporate_name,
                    CURRENT_DATABASE() AS legacy_contract_id
            FROM pessoas
            WHERE cpfcnpj IS NOT null
            UNION ALL
            SELECT 
                pessoas.id AS legacy_id,
                pessoas.cpfcnpj AS cpf_cnpj,
                    pessoas.nomerazao AS corporate_name,
                CURRENT_DATABASE() AS legacy_contract_id
            FROM pessoas
            WHERE cpfcnpj IS NULL
            ORDER BY 3
        SQL;
    }

    public function countSql(): ?string
    {
        return 'SELECT COUNT(*) AS count FROM pessoas';
    }

    public function validationRules(): array
    {
        return [
            'cpf_cnpj' => 'nullable|string|max:14',
            'corporate_name' => 'required|string|max:100',
        ];
    }
}
