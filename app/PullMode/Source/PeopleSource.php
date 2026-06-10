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
            WITH pessoas_normalized AS (
                SELECT
                    pessoas.id,
                    pessoas.nomerazao,
                    NULLIF(REGEXP_REPLACE(COALESCE(pessoas.cpfcnpj, ''), '[^0-9]', '', 'g'), '') AS normalized_cpf,
                    NULLIF(TRIM(pessoas.nomerazao), '') AS normalized_name
                FROM pessoas
            )
            SELECT legacy_id, cpf_cnpj, corporate_name, legacy_contract_id
            FROM (
                SELECT DISTINCT ON (normalized_cpf)
                    id AS legacy_id,
                    normalized_cpf AS cpf_cnpj,
                    nomerazao AS corporate_name,
                    CURRENT_DATABASE() AS legacy_contract_id
                FROM pessoas_normalized
                WHERE normalized_cpf IS NOT NULL
                ORDER BY normalized_cpf, id
            ) pessoas_com_cpf
            UNION ALL
            SELECT legacy_id, cpf_cnpj, corporate_name, legacy_contract_id
            FROM (
                SELECT DISTINCT ON (normalized_name)
                    id AS legacy_id,
                    NULL::text AS cpf_cnpj,
                    nomerazao AS corporate_name,
                    CURRENT_DATABASE() AS legacy_contract_id
                FROM pessoas_normalized
                WHERE normalized_cpf IS NULL
                  AND normalized_name IS NOT NULL
                ORDER BY normalized_name, id
            ) pessoas_sem_cpf
        SQL;
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            WITH pessoas_normalized AS (
                SELECT
                    pessoas.id,
                    NULLIF(REGEXP_REPLACE(COALESCE(pessoas.cpfcnpj, ''), '[^0-9]', '', 'g'), '') AS normalized_cpf,
                    NULLIF(TRIM(pessoas.nomerazao), '') AS normalized_name
                FROM pessoas
            ),
            pessoas_with_cpf AS (
                SELECT id
                FROM (
                    SELECT DISTINCT ON (normalized_cpf)
                        id
                    FROM pessoas_normalized
                    WHERE normalized_cpf IS NOT NULL
                    ORDER BY normalized_cpf, id
                ) pessoas_com_cpf
            ),
            pessoas_without_cpf AS (
                SELECT id
                FROM (
                    SELECT DISTINCT ON (normalized_name)
                        id
                    FROM pessoas_normalized
                    WHERE normalized_cpf IS NULL
                      AND normalized_name IS NOT NULL
                    ORDER BY normalized_name, id
                ) pessoas_sem_cpf
            )
            SELECT COUNT(*) AS count
            FROM (
                SELECT id FROM pessoas_with_cpf
                UNION ALL
                SELECT id FROM pessoas_without_cpf
            ) pessoas_migradas
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
