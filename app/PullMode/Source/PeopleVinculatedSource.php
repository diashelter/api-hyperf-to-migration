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
            WITH pessoas_normalized AS (
                SELECT
                    pessoas.id,
                    pessoas.nomerazao,
                    NULLIF(REGEXP_REPLACE(COALESCE(pessoas.cpfcnpj, ''), '[^0-9]', '', 'g'), '') AS normalized_cpf,
                    NULLIF(TRIM(pessoas.nomerazao), '') AS normalized_name
                FROM pessoas
            ),
            pessoas_ref AS (
                SELECT legacy_id AS canonical_people_id, normalized_cpf, normalized_name
                FROM (
                    SELECT DISTINCT ON (normalized_cpf)
                        id AS legacy_id,
                        normalized_cpf,
                        normalized_name
                    FROM pessoas_normalized
                    WHERE normalized_cpf IS NOT NULL
                    ORDER BY normalized_cpf, id
                ) pessoas_com_cpf
                UNION ALL
                SELECT legacy_id AS canonical_people_id, normalized_cpf, normalized_name
                FROM (
                    SELECT DISTINCT ON (normalized_name)
                        id AS legacy_id,
                        normalized_cpf,
                        normalized_name
                    FROM pessoas_normalized
                    WHERE normalized_cpf IS NULL
                      AND normalized_name IS NOT NULL
                    ORDER BY normalized_name, id
                ) pessoas_sem_cpf
            )
            SELECT 
                pessoas_vinculo.id AS legacy_id,
                pessoas_ref.canonical_people_id AS legacy_people_id,
                fk_empresa AS legacy_company_id,
                fk_compartilhamento AS legacy_rules_sharing_id,
                conta_deb AS debit_account,
                conta_cred AS credit_account,
                participante AS participant
            FROM pessoas_vinculo
                JOIN pessoas_normalized pessoas_vinculadas
                    ON pessoas_vinculadas.id = pessoas_vinculo.fk_pessoa
                JOIN pessoas_ref
                    ON (
                        pessoas_vinculadas.normalized_cpf IS NOT NULL
                        AND pessoas_ref.normalized_cpf = pessoas_vinculadas.normalized_cpf
                    )
                    OR (
                        pessoas_vinculadas.normalized_cpf IS NULL
                        AND pessoas_vinculadas.normalized_name IS NOT NULL
                        AND pessoas_ref.normalized_cpf IS NULL
                        AND pessoas_ref.normalized_name = pessoas_vinculadas.normalized_name
                    )
            ORDER BY 1
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
            pessoas_ref AS (
                SELECT canonical_people_id, normalized_cpf, normalized_name
                FROM (
                    SELECT DISTINCT ON (normalized_cpf)
                        id AS canonical_people_id,
                        normalized_cpf,
                        normalized_name
                    FROM pessoas_normalized
                    WHERE normalized_cpf IS NOT NULL
                    ORDER BY normalized_cpf, id
                ) pessoas_com_cpf
                UNION ALL
                SELECT canonical_people_id, normalized_cpf, normalized_name
                FROM (
                    SELECT DISTINCT ON (normalized_name)
                        id AS canonical_people_id,
                        normalized_cpf,
                        normalized_name
                    FROM pessoas_normalized
                    WHERE normalized_cpf IS NULL
                      AND normalized_name IS NOT NULL
                    ORDER BY normalized_name, id
                ) pessoas_sem_cpf
            )
            SELECT COUNT(*) AS count
            FROM pessoas_vinculo
                JOIN pessoas_normalized pessoas_vinculadas
                    ON pessoas_vinculadas.id = pessoas_vinculo.fk_pessoa
                JOIN pessoas_ref
                    ON (
                        pessoas_vinculadas.normalized_cpf IS NOT NULL
                        AND pessoas_ref.normalized_cpf = pessoas_vinculadas.normalized_cpf
                    )
                    OR (
                        pessoas_vinculadas.normalized_cpf IS NULL
                        AND pessoas_vinculadas.normalized_name IS NOT NULL
                        AND pessoas_ref.normalized_cpf IS NULL
                        AND pessoas_ref.normalized_name = pessoas_vinculadas.normalized_name
                    )
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
