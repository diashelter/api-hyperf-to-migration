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
            WITH pessoas_ref AS (
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
            )
            SELECT 
                pessoas_vinculo.id AS legacy_id,
                pessoas_ref.legacy_id AS legacy_people_id,
                fk_empresa AS legacy_company_id,
                fk_compartilhamento AS legacy_rules_sharing_id,
                conta_deb AS debit_account,
                conta_cred AS credit_account,
                participante AS participant
            FROM pessoas_vinculo
                JOIN pessoas_ref ON pessoas_ref.legacy_id = pessoas_vinculo.fk_pessoa
            ORDER BY 1
        SQL;
    }

    public function countSql(): ?string
    {
        return 'SELECT COUNT(*) AS count FROM pessoas_vinculo';
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
