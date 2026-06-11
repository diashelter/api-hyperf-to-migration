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

namespace App\Service;

use Hyperf\DbConnection\Db;

class LegacyDuplicateValidationService
{
    private const SAMPLE_GROUP_LIMIT = 20;

    private const SAMPLE_LEGACY_IDS_LIMIT = 10;

    private const UUID_PATTERN = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';

    /**
     * @return array<string, mixed>
     */
    public function validate(string $legacyDb, string $legacyConnection): array
    {
        $rules = $this->rules();
        $violations = [];

        foreach ($rules as $rule) {
            $samples = Db::connection($legacyConnection)->select($rule['samples_sql']);
            if ($samples === []) {
                continue;
            }

            $totals = Db::connection($legacyConnection)->selectOne($rule['totals_sql']);

            $violations[] = [
                'entity' => $rule['entity'],
                'table' => $rule['table'],
                'rule' => $rule['rule'],
                'field' => $rule['field'],
                'total_groups' => (int) ($totals->total_groups ?? 0),
                'total_records' => (int) ($totals->total_records ?? 0),
                'samples' => array_map(
                    fn($sample) => [
                        'value' => $sample->value === null ? null : (string) $sample->value,
                        'count' => (int) $sample->duplicate_count,
                        'legacy_ids' => $this->explodeLegacyIds((string) $sample->legacy_ids),
                    ],
                    $samples
                ),
            ];
        }

        return $this->buildSummaryPayload($legacyDb, $violations, $rules);
    }

    /**
     * @param array<int, array<string, mixed>> $violations
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, mixed>
     */
    public function buildSummaryPayload(string $legacyDb, array $violations, array $rules): array
    {
        $entitiesChecked = count(array_unique(array_column($rules, 'entity')));

        return [
            'legacy_db' => $legacyDb,
            'has_violations' => $violations !== [],
            'summary' => [
                'entities_checked' => $entitiesChecked,
                'rules_checked' => count($rules),
                'violations' => count($violations),
            ],
            'violations' => $violations,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function explodeLegacyIds(string $legacyIds): array
    {
        if ($legacyIds === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $legacyIds))));
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function rules(): array
    {
        $rules = [
            ...$this->userRules(),
            ...$this->contractRules(),
            ...$this->planRules(),
            ...$this->rulesSharingRules(),
            ...$this->layoutRules(),
            ...$this->companyRules(),
            ...$this->companyLayoutRules(),
            ...$this->companyLayoutFixedAccountRules(),
            ...$this->peopleRules(),
            ...$this->peopleVinculatedRules(),
            ...$this->importRules(),
            ...$this->importSessionRules(),
            ...$this->accountingRuleRules(),
            ...$this->importRecordRules(),
            ...$this->ignoredConciliationTermRules(),
            ...$this->confrontationRules(),
            ...$this->confrontationRecordRules(),
            ...$this->confrontationConciliationRules(),
            ...$this->userCompanyRestrictionRules(),
            ...$this->openFinanceRules(),
        ];

        return $rules;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function userRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'users',
                table: 'usuarios',
                rule: 'missing_email',
                field: 'email',
                invalidRowsSql: <<<'SQL'
                    SELECT 'email vazio ou nulo' AS value, COALESCE(pk::text, '') AS legacy_id
                    FROM usuarios
                    WHERE (email IS NULL OR TRIM(email) = '')
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'users',
                table: 'usuarios',
                rule: 'missing_name',
                field: 'name',
                invalidRowsSql: <<<'SQL'
                    SELECT 'nome vazio ou nulo' AS value, COALESCE(pk::text, '') AS legacy_id
                    FROM usuarios
                    WHERE email <> 'suporte@integradorcontabil.net.br'
                      AND (nome IS NULL OR TRIM(nome) = '')
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'users',
                table: 'usuarios',
                rule: 'missing_password',
                field: 'password',
                invalidRowsSql: <<<'SQL'
                    SELECT 'senha vazia ou nula' AS value, COALESCE(pk::text, '') AS legacy_id
                    FROM usuarios
                    WHERE email <> 'suporte@integradorcontabil.net.br'
                      AND (senha IS NULL OR TRIM(senha) = '')
                SQL
            ),
            $this->buildDuplicateRule(
                entity: 'users',
                table: 'usuarios',
                rule: 'duplicate_email',
                field: 'email',
                duplicateExpression: 'LOWER(TRIM(email))',
                tableExpression: 'usuarios',
                whereClause: "email IS NOT NULL AND TRIM(email) <> '' AND LOWER(TRIM(email)) <> 'suporte@integradorcontabil.net.br'",
                legacyIdsExpression: "COALESCE(pk::text, '')"
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function contractRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'contracts',
                table: 'contrato',
                rule: 'invalid_cpf_cnpj',
                field: 'cpf_cnpj',
                invalidRowsSql: <<<'SQL'
                    SELECT
                        CASE
                            WHEN REGEXP_REPLACE(COALESCE(cnpj, ''), '[^0-9]', '', 'g') = '' THEN 'cnpj vazio ou nulo'
                            ELSE 'cnpj com tamanho diferente de 14'
                        END AS value,
                        COALESCE(pk::text, '') AS legacy_id
                    FROM contrato
                    WHERE pk <> 0
                      AND LENGTH(REGEXP_REPLACE(COALESCE(cnpj, ''), '[^0-9]', '', 'g')) <> 14
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function planRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'plans',
                table: 'pcontasconc',
                rule: 'invalid_name',
                field: 'name',
                invalidRowsSql: <<<'SQL'
                    SELECT
                        CASE
                            WHEN nome IS NULL OR TRIM(nome) = '' THEN 'nome vazio ou nulo'
                            ELSE 'nome acima de 70 caracteres'
                        END AS value,
                        COALESCE(pk::text, '') AS legacy_id
                    FROM pcontasconc
                    WHERE nome IS NULL OR TRIM(nome) = '' OR LENGTH(nome) > 70
                SQL
            ),
            $this->buildDuplicateRule(
                entity: 'plans',
                table: 'pcontasconc',
                rule: 'duplicate_code',
                field: 'code',
                duplicateExpression: 'pk::text',
                tableExpression: 'pcontasconc',
                whereClause: 'pk IS NOT NULL',
                legacyIdsExpression: "COALESCE(pk::text, '')"
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function rulesSharingRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'rules_sharings',
                table: 'plano_contas',
                rule: 'invalid_code_or_name',
                field: 'code,name',
                invalidRowsSql: <<<'SQL'
                    SELECT
                        CASE
                            WHEN pk IS NULL THEN 'codigo nulo'
                            WHEN nome IS NULL OR TRIM(nome) = '' THEN 'nome vazio ou nulo'
                            ELSE 'nome acima de 100 caracteres'
                        END AS value,
                        COALESCE(pk::text, '') AS legacy_id
                    FROM plano_contas
                    WHERE pk <> 0
                      AND (pk IS NULL OR nome IS NULL OR TRIM(nome) = '' OR LENGTH(nome) > 100)
                SQL
            ),
            $this->buildDuplicateRule(
                entity: 'rules_sharings',
                table: 'plano_contas',
                rule: 'duplicate_code_name',
                field: 'code,name',
                duplicateExpression: "pk::text || '|' || COALESCE(TRIM(nome), '')",
                tableExpression: 'plano_contas',
                whereClause: 'pk <> 0',
                legacyIdsExpression: "COALESCE(pk::text, '')"
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function layoutRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'layouts',
                table: 'layout',
                rule: 'missing_name',
                field: 'name',
                invalidRowsSql: <<<'SQL'
                    SELECT 'nome vazio ou nulo' AS value, COALESCE(layout.pk::text, '') AS legacy_id
                    FROM public.layout
                    JOIN layout_empresa ON layout.pk = layout_empresa.fk_layoutimp
                    WHERE visivel = 1
                      AND tipo = 'IMP'
                      AND (nome IS NULL OR TRIM(nome) = '')
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'layouts',
                table: 'layout',
                rule: 'invalid_movement_type',
                field: 'movement_type',
                invalidRowsSql: <<<'SQL'
                    SELECT COALESCE(INITCAP(tipomovimento), '<null>') AS value, COALESCE(layout.pk::text, '') AS legacy_id
                    FROM public.layout
                    JOIN layout_empresa ON layout.pk = layout_empresa.fk_layoutimp
                    WHERE visivel = 1
                      AND tipo = 'IMP'
                      AND COALESCE(INITCAP(tipomovimento), 'Ambos') NOT IN ('Ambos', 'Pagar', 'Receber')
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'layouts',
                table: 'layout',
                rule: 'invalid_sector',
                field: 'sector',
                invalidRowsSql: <<<'SQL'
                    SELECT COALESCE(setor::text, '<null>') AS value, COALESCE(layout.pk::text, '') AS legacy_id
                    FROM public.layout
                    JOIN layout_empresa ON layout.pk = layout_empresa.fk_layoutimp
                    WHERE visivel = 1
                      AND tipo = 'IMP'
                      AND setor IS NOT NULL
                      AND setor NOT IN ('C', 'F')
                SQL
            ),
            $this->buildDuplicateRule(
                entity: 'layouts',
                table: 'layout',
                rule: 'duplicate_code',
                field: 'code',
                duplicateExpression: 'layout.pk::text',
                tableExpression: 'public.layout JOIN layout_empresa ON layout.pk = layout_empresa.fk_layoutimp',
                whereClause: "visivel = 1 AND tipo = 'IMP'",
                legacyIdsExpression: "COALESCE(layout.pk::text, '')"
            ),
            $this->buildMissingReferenceRule(
                entity: 'layouts',
                table: 'layout',
                rule: 'missing_reference_layout',
                field: 'reference_layout',
                invalidRowsSql: <<<'SQL'
                    SELECT layout.fk_layout_mestre::text AS value, COALESCE(layout.pk::text, '') AS legacy_id
                    FROM public.layout
                    JOIN layout_empresa ON layout.pk = layout_empresa.fk_layoutimp
                    LEFT JOIN public.layout master_layout
                        ON master_layout.pk = layout.fk_layout_mestre
                       AND master_layout.visivel = 1
                       AND master_layout.tipo = 'IMP'
                    WHERE layout.visivel = 1
                      AND layout.tipo = 'IMP'
                      AND layout.fk_layout_mestre IS NOT NULL
                      AND master_layout.pk IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function companyRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'companies',
                table: 'empresas',
                rule: 'invalid_cpf_cnpj',
                field: 'cpf_cnpj',
                invalidRowsSql: <<<'SQL'
                    SELECT
                        CASE
                            WHEN REGEXP_REPLACE(COALESCE(cnpj, ''), '[^0-9]', '', 'g') = '' THEN 'cnpj vazio ou nulo'
                            ELSE 'cnpj com tamanho diferente de 14'
                        END AS value,
                        COALESCE(pk::text, '') AS legacy_id
                    FROM empresas
                    WHERE pk <> 0
                      AND LENGTH(LEFT(REGEXP_REPLACE(COALESCE(cnpj, ''), '[^0-9]', '', 'g'), 14)) <> 14
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'companies',
                table: 'empresas',
                rule: 'invalid_tax_regime',
                field: 'tax_regime',
                invalidRowsSql: <<<'SQL'
                    SELECT COALESCE(INITCAP(tributacao), '<null>') AS value, COALESCE(pk::text, '') AS legacy_id
                    FROM empresas
                    WHERE pk <> 0
                      AND COALESCE(INITCAP(tributacao), 'Outros') NOT IN ('Lucro Real', 'Lucro Presumido', 'Simples Nacional', 'Outros')
                SQL
            ),
            $this->buildDuplicateRule(
                entity: 'companies',
                table: 'empresas',
                rule: 'duplicate_code',
                field: 'code',
                duplicateExpression: 'pk::text',
                tableExpression: 'empresas',
                whereClause: 'pk <> 0',
                legacyIdsExpression: "COALESCE(pk::text, '')"
            ),
            $this->buildMissingReferenceRule(
                entity: 'companies',
                table: 'empresas',
                rule: 'missing_plan_reference',
                field: 'plan_id',
                invalidRowsSql: <<<'SQL'
                    SELECT empresas.fk_pcontasconc::text AS value, COALESCE(empresas.pk::text, '') AS legacy_id
                    FROM empresas
                    LEFT JOIN pcontasconc ON pcontasconc.pk = empresas.fk_pcontasconc
                    WHERE empresas.pk <> 0
                      AND empresas.fk_pcontasconc IS NOT NULL
                      AND empresas.fk_pcontasconc <> 0
                      AND pcontasconc.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'companies',
                table: 'empresas',
                rule: 'missing_rules_sharing_reference',
                field: 'rules_sharing_id',
                invalidRowsSql: <<<'SQL'
                    SELECT empresas.fk_plano_contas::text AS value, COALESCE(empresas.pk::text, '') AS legacy_id
                    FROM empresas
                    LEFT JOIN plano_contas ON plano_contas.pk = empresas.fk_plano_contas AND plano_contas.pk <> 0
                    WHERE empresas.pk <> 0
                      AND empresas.fk_plano_contas IS NOT NULL
                      AND empresas.fk_plano_contas <> 0
                      AND plano_contas.pk IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function companyLayoutRules(): array
    {
        return [
            $this->buildMissingReferenceRule(
                entity: 'company_layout',
                table: 'layout_empresa',
                rule: 'missing_company_reference',
                field: 'company_id',
                invalidRowsSql: <<<'SQL'
                    SELECT layout_empresa.fk_empresa::text AS value, COALESCE(layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
                    LEFT JOIN empresas ON empresas.pk = layout_empresa.fk_empresa AND empresas.pk <> 0
                    WHERE layout_empresa.fk_empresa <> 0
                      AND layout_empresa.fk_layoutimp <> 0
                      AND empresas.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'company_layout',
                table: 'layout_empresa',
                rule: 'missing_layout_imp_reference',
                field: 'layout_imp',
                invalidRowsSql: <<<'SQL'
                    SELECT layout_empresa.fk_layoutimp::text AS value, COALESCE(layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    LEFT JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1 AND layout.tipo = 'IMP'
                    WHERE layout_empresa.fk_empresa <> 0
                      AND layout_empresa.fk_layoutimp <> 0
                      AND layout.pk IS NULL
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'company_layout',
                table: 'layout_empresa',
                rule: 'missing_layout_exp',
                field: 'layout_exp',
                invalidRowsSql: <<<'SQL'
                    SELECT 'fk_layoutexp nulo ou zero' AS value, COALESCE(layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
                    WHERE layout_empresa.fk_empresa <> 0
                      AND layout_empresa.fk_layoutimp <> 0
                      AND COALESCE(layout_empresa.fk_layoutexp, 0) = 0
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function companyLayoutFixedAccountRules(): array
    {
        return [
            $this->buildMissingReferenceRule(
                entity: 'company_layout_fixed_accounts',
                table: 'layout_empresa',
                rule: 'missing_company_layout_reference',
                field: 'company_layout_id',
                invalidRowsSql: <<<'SQL'
                    SELECT layout_empresa.pk::text AS value, 'LF-' || COALESCE(layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    LEFT JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
                    WHERE layout_empresa.conta_fixa_hab = true
                      AND (layout_empresa.fk_empresa = 0 OR layout_empresa.fk_layoutimp = 0 OR layout.pk IS NULL)
                SQL
            ),
            $this->buildDuplicateRule(
                entity: 'company_layout_fixed_accounts',
                table: 'layout_empresa',
                rule: 'duplicate_company_layout_bank_account',
                field: 'company_layout_id,bank_account',
                duplicateExpression: "layout_empresa.pk::text || '|' || COALESCE(layout_empresa.conta_fixa, '')",
                tableExpression: 'layout_empresa JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1',
                whereClause: "layout_empresa.conta_fixa_hab = true AND layout_empresa.fk_empresa <> 0 AND layout_empresa.fk_layoutimp <> 0 AND COALESCE(layout_empresa.conta_fixa, '') <> ''",
                legacyIdsExpression: "'LF-' || COALESCE(layout_empresa.pk::text, '')"
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function peopleRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'peoples',
                table: 'pessoas',
                rule: 'missing_corporate_name',
                field: 'corporate_name',
                invalidRowsSql: <<<'SQL'
                    SELECT 'nomerazao vazio ou nulo' AS value, COALESCE(id::text, '') AS legacy_id
                    FROM pessoas
                    WHERE nomerazao IS NULL OR TRIM(nomerazao) = ''
                SQL
            ),
            $this->buildDuplicateRule(
                entity: 'peoples',
                table: 'pessoas',
                rule: 'duplicate_cpf_cnpj',
                field: 'cpf_cnpj',
                duplicateExpression: "NULLIF(REGEXP_REPLACE(COALESCE(cpfcnpj, ''), '[^0-9]', '', 'g'), '')",
                tableExpression: 'pessoas',
                whereClause: "NULLIF(REGEXP_REPLACE(COALESCE(cpfcnpj, ''), '[^0-9]', '', 'g'), '') IS NOT NULL",
                legacyIdsExpression: "COALESCE(id::text, '')"
            ),
            $this->buildDuplicateRule(
                entity: 'peoples',
                table: 'pessoas',
                rule: 'duplicate_name_without_cpf_cnpj',
                field: 'corporate_name',
                duplicateExpression: 'LOWER(TRIM(nomerazao))',
                tableExpression: 'pessoas',
                whereClause: "NULLIF(REGEXP_REPLACE(COALESCE(cpfcnpj, ''), '[^0-9]', '', 'g'), '') IS NULL AND nomerazao IS NOT NULL AND TRIM(nomerazao) <> ''",
                legacyIdsExpression: "COALESCE(id::text, '')"
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function peopleVinculatedRules(): array
    {
        return [
            $this->buildMissingReferenceRule(
                entity: 'people_vinculated',
                table: 'pessoas_vinculo',
                rule: 'missing_people_reference',
                field: 'people_id',
                invalidRowsSql: <<<'SQL'
                    SELECT pessoas_vinculo.fk_pessoa::text AS value, COALESCE(pessoas_vinculo.id::text, '') AS legacy_id
                    FROM pessoas_vinculo
                    LEFT JOIN pessoas ON pessoas.id = pessoas_vinculo.fk_pessoa
                    WHERE pessoas.id IS NULL
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'people_vinculated',
                table: 'pessoas_vinculo',
                rule: 'invalid_company_or_rules_sharing_scope',
                field: 'company_id,rules_sharing_id',
                invalidRowsSql: <<<'SQL'
                    SELECT
                        CASE
                            WHEN COALESCE(fk_empresa, 0) = 0 AND COALESCE(fk_compartilhamento, 0) = 0 THEN 'empresa e compartilhamento vazios'
                            ELSE 'empresa e compartilhamento preenchidos ao mesmo tempo'
                        END AS value,
                        COALESCE(id::text, '') AS legacy_id
                    FROM pessoas_vinculo
                    WHERE (COALESCE(fk_empresa, 0) = 0 AND COALESCE(fk_compartilhamento, 0) = 0)
                       OR (COALESCE(fk_empresa, 0) <> 0 AND COALESCE(fk_compartilhamento, 0) <> 0)
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'people_vinculated',
                table: 'pessoas_vinculo',
                rule: 'missing_company_reference',
                field: 'company_id',
                invalidRowsSql: <<<'SQL'
                    SELECT pessoas_vinculo.fk_empresa::text AS value, COALESCE(pessoas_vinculo.id::text, '') AS legacy_id
                    FROM pessoas_vinculo
                    LEFT JOIN empresas ON empresas.pk = pessoas_vinculo.fk_empresa AND empresas.pk <> 0
                    WHERE COALESCE(pessoas_vinculo.fk_empresa, 0) <> 0
                      AND empresas.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'people_vinculated',
                table: 'pessoas_vinculo',
                rule: 'missing_rules_sharing_reference',
                field: 'rules_sharing_id',
                invalidRowsSql: <<<'SQL'
                    SELECT pessoas_vinculo.fk_compartilhamento::text AS value, COALESCE(pessoas_vinculo.id::text, '') AS legacy_id
                    FROM pessoas_vinculo
                    LEFT JOIN plano_contas ON plano_contas.pk = pessoas_vinculo.fk_compartilhamento AND plano_contas.pk <> 0
                    WHERE COALESCE(pessoas_vinculo.fk_compartilhamento, 0) <> 0
                      AND plano_contas.pk IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function importRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'imports',
                table: 'importacao',
                rule: 'missing_admin_user',
                field: 'user_id',
                invalidRowsSql: <<<'SQL'
                    SELECT 'usuario administrador nao encontrado' AS value, 'IMP-' || COALESCE(importacao.fk_layoutempresa::text, '') AS legacy_id
                    FROM importacao
                    JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
                    WHERE fk_layout <> 0
                    GROUP BY importacao.fk_empresa, fk_layoutempresa
                    HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
                       AND (SELECT pk FROM usuarios WHERE administrador = 1 AND pk <> 1 LIMIT 1) IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'imports',
                table: 'importacao',
                rule: 'missing_company_reference',
                field: 'company_id',
                invalidRowsSql: <<<'SQL'
                    SELECT importacao.fk_empresa::text AS value, 'IMP-' || COALESCE(importacao.fk_layoutempresa::text, '') AS legacy_id
                    FROM importacao
                    JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
                    LEFT JOIN empresas ON empresas.pk = importacao.fk_empresa AND empresas.pk <> 0
                    WHERE fk_layout <> 0
                    GROUP BY importacao.fk_empresa, fk_layoutempresa, empresas.pk
                    HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
                       AND empresas.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'imports',
                table: 'importacao',
                rule: 'missing_company_layout_reference',
                field: 'company_layout_id',
                invalidRowsSql: <<<'SQL'
                    SELECT importacao.fk_layoutempresa::text AS value, 'IMP-' || COALESCE(importacao.fk_layoutempresa::text, '') AS legacy_id
                    FROM importacao
                    LEFT JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
                    WHERE importacao.fk_layout <> 0
                    GROUP BY importacao.fk_empresa, importacao.fk_layoutempresa, layout_empresa.pk
                    HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
                       AND layout_empresa.pk IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function importSessionRules(): array
    {
        return [
            $this->buildMissingReferenceRule(
                entity: 'import_sessions',
                table: 'importacao',
                rule: 'missing_import_reference',
                field: 'import_id',
                invalidRowsSql: <<<'SQL'
                    SELECT importacao.fk_layoutempresa::text AS value, 'IS-' || COALESCE(importacao.fk_layoutempresa::text, '') AS legacy_id
                    FROM importacao
                    LEFT JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
                    WHERE importacao.fk_layout <> 0
                    GROUP BY importacao.fk_layoutempresa, importacao.fk_layout, layout_empresa.pk
                    HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
                       AND layout_empresa.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'import_sessions',
                table: 'importacao',
                rule: 'missing_layout_reference',
                field: 'layout_id',
                invalidRowsSql: <<<'SQL'
                    SELECT importacao.fk_layout::text AS value, 'IS-' || COALESCE(importacao.fk_layoutempresa::text, '') AS legacy_id
                    FROM importacao
                    LEFT JOIN layout ON layout.pk = importacao.fk_layout AND layout.visivel = 1 AND layout.tipo = 'IMP'
                    WHERE importacao.fk_layout <> 0
                    GROUP BY importacao.fk_layoutempresa, importacao.fk_layout, layout.pk
                    HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
                       AND layout.pk IS NULL
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'import_sessions',
                table: 'importacao',
                rule: 'missing_file_name',
                field: 'file_name',
                invalidRowsSql: <<<'SQL'
                    SELECT 'nome de arquivo vazio apos fallback' AS value, 'IS-' || COALESCE(importacao.fk_layoutempresa::text, '') AS legacy_id
                    FROM importacao
                    JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
                    WHERE importacao.fk_layout <> 0
                    GROUP BY importacao.fk_layoutempresa, importacao.fk_layout
                    HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
                       AND COALESCE(MIN(NULLIF(importacao.arquivo, '')), 'legacy-import-' || importacao.fk_layoutempresa || '.txt') IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function accountingRuleRules(): array
    {
        return [
            $this->buildMissingReferenceRule(
                entity: 'rules',
                table: 'regras',
                rule: 'missing_company_reference',
                field: 'company_id',
                invalidRowsSql: <<<'SQL'
                    SELECT layout_empresa.fk_empresa::text AS value, COALESCE(regras.id::text, '') AS legacy_id
                    FROM regras
                    JOIN layout_empresa ON regras.fk_layout_empresa = layout_empresa.pk
                    JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1 AND layout.tipo = 'IMP'
                    LEFT JOIN empresas ON empresas.pk = layout_empresa.fk_empresa AND empresas.pk <> 0
                    WHERE fk_layoutimp <> 0
                      AND empresas.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'rules',
                table: 'regras',
                rule: 'missing_layout_reference',
                field: 'layout_id',
                invalidRowsSql: <<<'SQL'
                    SELECT layout_empresa.fk_layoutimp::text AS value, COALESCE(regras.id::text, '') AS legacy_id
                    FROM regras
                    JOIN layout_empresa ON regras.fk_layout_empresa = layout_empresa.pk
                    LEFT JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1 AND layout.tipo = 'IMP'
                    WHERE fk_layoutimp <> 0
                      AND layout.pk IS NULL
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'rules',
                table: 'regras',
                rule: 'malformed_token',
                field: 'token',
                invalidRowsSql: <<<'SQL'
                    SELECT COALESCE(regras.token, '<null>') AS value, COALESCE(regras.id::text, '') AS legacy_id
                    FROM regras
                    JOIN layout_empresa ON regras.fk_layout_empresa = layout_empresa.pk
                    JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1 AND layout.tipo = 'IMP'
                    WHERE fk_layoutimp <> 0
                      AND regras.token IS NOT NULL
                      AND ARRAY_LENGTH(STRING_TO_ARRAY(REPLACE(REPLACE(regras.token, '(', ''), ')', ''), ','), 1) < 9
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function importRecordRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'import_records',
                table: 'importacao',
                rule: 'missing_uuid',
                field: 'id',
                invalidRowsSql: <<<'SQL'
                    SELECT 'uuid vazio ou nulo' AS value, COALESCE(importacao.uuid::text, importacao.fk_layoutempresa::text, '') AS legacy_id
                    FROM importacao
                    JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
                    WHERE importacao.fk_layout <> 0
                      AND importacao.inclusao > NOW() - INTERVAL '60 days'
                      AND importacao.uuid IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'import_records',
                table: 'importacao',
                rule: 'missing_import_reference',
                field: 'import_id',
                invalidRowsSql: <<<'SQL'
                    SELECT importacao.fk_layoutempresa::text AS value, COALESCE(importacao.uuid::text, '') AS legacy_id
                    FROM importacao
                    LEFT JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
                    WHERE importacao.fk_layout <> 0
                      AND importacao.inclusao > NOW() - INTERVAL '60 days'
                      AND layout_empresa.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'import_records',
                table: 'importacao',
                rule: 'missing_import_session_reference',
                field: 'import_session_id',
                invalidRowsSql: <<<'SQL'
                    SELECT importacao.fk_layoutempresa::text AS value, COALESCE(importacao.uuid::text, '') AS legacy_id
                    FROM importacao
                    LEFT JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
                    WHERE importacao.fk_layout <> 0
                      AND importacao.inclusao > NOW() - INTERVAL '60 days'
                      AND layout_empresa.pk IS NULL
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'import_records',
                table: 'importacao',
                rule: 'invalid_conciliation_status',
                field: 'conciliation_status',
                invalidRowsSql: <<<'SQL'
                    SELECT COALESCE(importacao.situacao_confronto, '<null>') AS value, COALESCE(importacao.uuid::text, '') AS legacy_id
                    FROM importacao
                    JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
                    WHERE importacao.fk_layout <> 0
                      AND importacao.inclusao > NOW() - INTERVAL '60 days'
                      AND importacao.situacao_confronto IS NOT NULL
                      AND importacao.situacao_confronto NOT IN ('CO', 'NC', 'AF')
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function ignoredConciliationTermRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'ignored_conciliation_terms',
                table: 'layout_despresados',
                rule: 'missing_history',
                field: 'history',
                invalidRowsSql: <<<'SQL'
                    SELECT 'historico nulo com fornecedor preenchido' AS value, COALESCE(pk::text, '') AS legacy_id
                    FROM layout_despresados
                    WHERE fk_layout <> 0
                      AND historico IS NULL
                      AND fornecedor IS NOT NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'ignored_conciliation_terms',
                table: 'layout_despresados',
                rule: 'missing_layout_reference',
                field: 'layout_id',
                invalidRowsSql: <<<'SQL'
                    SELECT layout_despresados.fk_layout::text AS value, COALESCE(layout_despresados.pk::text, '') AS legacy_id
                    FROM layout_despresados
                    LEFT JOIN layout ON layout.pk = layout_despresados.fk_layout AND layout.visivel = 1 AND layout.tipo = 'IMP'
                    WHERE layout_despresados.fk_layout <> 0
                      AND (layout_despresados.historico IS NOT NULL OR layout_despresados.fornecedor IS NOT NULL)
                      AND layout.pk IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function confrontationRules(): array
    {
        return [
            $this->buildInvalidRowsRule(
                entity: 'confrontations',
                table: 'confrontos',
                rule: 'missing_description',
                field: 'description',
                invalidRowsSql: <<<'SQL'
                    SELECT 'descricao vazia ou nula' AS value, COALESCE(confrontos.pk::text, '') AS legacy_id
                    FROM confrontos
                    WHERE confrontos.created_at > NOW() - INTERVAL '60 days'
                      AND (descricao IS NULL OR TRIM(descricao) = '')
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'confrontations',
                table: 'confrontos',
                rule: 'missing_company_reference',
                field: 'company_id',
                invalidRowsSql: <<<'SQL'
                    SELECT confrontos.fk_empresa::text AS value, COALESCE(confrontos.pk::text, '') AS legacy_id
                    FROM confrontos
                    LEFT JOIN empresas ON empresas.pk = confrontos.fk_empresa AND empresas.pk <> 0
                    WHERE confrontos.created_at > NOW() - INTERVAL '60 days'
                      AND empresas.pk IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function confrontationRecordRules(): array
    {
        return [
            $this->buildMissingReferenceRule(
                entity: 'confrontation_records',
                table: 'confrontos_itens',
                rule: 'missing_confrontation_reference',
                field: 'confrontation_id',
                invalidRowsSql: <<<'SQL'
                    SELECT confrontos_itens.fk_confrontos::text AS value, COALESCE(confrontos_itens.pk::text, '') AS legacy_id
                    FROM confrontos_itens
                    LEFT JOIN confrontos ON confrontos.pk = confrontos_itens.fk_confrontos
                    WHERE confrontos_itens.fk_layoutimp <> 0
                      AND confrontos_itens.created_at > NOW() - INTERVAL '60 days'
                      AND confrontos.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'confrontation_records',
                table: 'confrontos_itens',
                rule: 'missing_import_record_reference',
                field: 'import_record_id',
                invalidRowsSql: <<<'SQL'
                    SELECT COALESCE(confrontos_itens.uuid::text, '<null>') AS value, COALESCE(confrontos_itens.pk::text, '') AS legacy_id
                    FROM confrontos_itens
                    LEFT JOIN importacao ON importacao.uuid = confrontos_itens.uuid
                    WHERE confrontos_itens.fk_layoutimp <> 0
                      AND confrontos_itens.created_at > NOW() - INTERVAL '60 days'
                      AND confrontos_itens.uuid IS NOT NULL
                      AND importacao.uuid IS NULL
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'confrontation_records',
                table: 'confrontos_itens',
                rule: 'invalid_required_fields',
                field: 'date,layout_code,debit_credit,value',
                invalidRowsSql: <<<'SQL'
                    SELECT
                        CASE
                            WHEN "data" IS NULL THEN 'data nula'
                            WHEN fk_layoutimp IS NULL OR fk_layoutimp = 0 THEN 'layout_code nulo ou zero'
                            WHEN cd NOT IN ('D', 'C') THEN 'debit_credit invalido'
                            ELSE 'valor nulo'
                        END AS value,
                        COALESCE(pk::text, '') AS legacy_id
                    FROM confrontos_itens
                    WHERE created_at > NOW() - INTERVAL '60 days'
                      AND ("data" IS NULL OR fk_layoutimp IS NULL OR fk_layoutimp = 0 OR cd NOT IN ('D', 'C') OR valor IS NULL)
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'confrontation_records',
                table: 'confrontos_itens',
                rule: 'invalid_records_origin',
                field: 'records_origin',
                invalidRowsSql: <<<'SQL'
                    SELECT COALESCE(tipo, '<null>') AS value, COALESCE(pk::text, '') AS legacy_id
                    FROM confrontos_itens
                    WHERE fk_layoutimp <> 0
                      AND created_at > NOW() - INTERVAL '60 days'
                      AND tipo IS NOT NULL
                      AND tipo NOT IN ('F', 'B', 'A')
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function confrontationConciliationRules(): array
    {
        return [
            $this->buildDuplicateRule(
                entity: 'confrontation_conciliations',
                table: 'confrontos_itens',
                rule: 'duplicate_bank_financial_pair',
                field: 'confrontation_records_bank,confrontation_records_financial',
                duplicateExpression: "bank.pk::text || '|' || confrontos_itens.pk::text",
                tableExpression: 'confrontos_itens JOIN confrontos_itens bank ON confrontos_itens.fk_confrontos_item = bank.pk',
                whereClause: "confrontos_itens.fk_layoutimp <> 0 AND confrontos_itens.created_at > NOW() - INTERVAL '60 days' AND confrontos_itens.tipo = 'F'",
                legacyIdsExpression: "COALESCE(confrontos_itens.pk::text, '')"
            ),
            $this->buildMissingReferenceRule(
                entity: 'confrontation_conciliations',
                table: 'confrontos_itens',
                rule: 'missing_bank_or_financial_record_reference',
                field: 'confrontation_records_bank,confrontation_records_financial',
                invalidRowsSql: <<<'SQL'
                    SELECT
                        CASE WHEN bank.pk IS NULL THEN 'registro bancario ausente' ELSE 'registro financeiro ausente' END AS value,
                        COALESCE(confrontos_itens.pk::text, '') AS legacy_id
                    FROM confrontos_itens
                    LEFT JOIN confrontos_itens bank ON confrontos_itens.fk_confrontos_item = bank.pk
                    WHERE confrontos_itens.fk_layoutimp <> 0
                      AND confrontos_itens.created_at > NOW() - INTERVAL '60 days'
                      AND confrontos_itens.tipo = 'F'
                      AND bank.pk IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function userCompanyRestrictionRules(): array
    {
        return [
            $this->buildMissingReferenceRule(
                entity: 'user_company_restrictions',
                table: 'usuario_empresas',
                rule: 'missing_user_reference',
                field: 'user_id',
                invalidRowsSql: <<<'SQL'
                    SELECT usuario_empresas.fk_usuario::text AS value, COALESCE(usuario_empresas.pk::text, '') AS legacy_id
                    FROM usuario_empresas
                    LEFT JOIN usuarios ON usuarios.pk = usuario_empresas.fk_usuario
                    WHERE usuarios.pk IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'user_company_restrictions',
                table: 'usuario_empresas',
                rule: 'missing_company_reference',
                field: 'company_id',
                invalidRowsSql: <<<'SQL'
                    SELECT usuario_empresas.fk_empresa::text AS value, COALESCE(usuario_empresas.pk::text, '') AS legacy_id
                    FROM usuario_empresas
                    LEFT JOIN empresas ON empresas.pk = usuario_empresas.fk_empresa AND empresas.pk <> 0
                    WHERE empresas.pk IS NULL
                SQL
            ),
            $this->buildDuplicateRule(
                entity: 'user_company_restrictions',
                table: 'usuario_empresas',
                rule: 'duplicate_user_company',
                field: 'user_id,company_id',
                duplicateExpression: "fk_usuario::text || '|' || fk_empresa::text",
                tableExpression: 'usuario_empresas',
                whereClause: 'fk_usuario IS NOT NULL AND fk_empresa IS NOT NULL',
                legacyIdsExpression: "COALESCE(pk::text, '')"
            ),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function openFinanceRules(): array
    {
        $uuidPattern = self::UUID_PATTERN;

        return [
            $this->buildInvalidRowsRule(
                entity: 'open_finance_connections',
                table: 'layout_empresa',
                rule: 'invalid_itemid_uuid',
                field: 'id',
                invalidRowsSql: <<<SQL
                    SELECT COALESCE(layout_empresa.itemid, '<null>') AS value, COALESCE(layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
                    WHERE fk_empresa <> 0
                      AND fk_layoutimp <> 0
                      AND layout_empresa.hab_belvo = true
                      AND itemid IS NOT NULL
                      AND itemid !~ '{$uuidPattern}'
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'open_finance_connections',
                table: 'layout_empresa',
                rule: 'missing_company_reference',
                field: 'company_id',
                invalidRowsSql: <<<'SQL'
                    SELECT layout_empresa.fk_empresa::text AS value, COALESCE(layout_empresa.itemid, layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
                    LEFT JOIN empresas ON empresas.pk = layout_empresa.fk_empresa AND empresas.pk <> 0
                    WHERE fk_empresa <> 0
                      AND fk_layoutimp <> 0
                      AND layout_empresa.hab_belvo = true
                      AND itemid IS NOT NULL
                      AND empresas.pk IS NULL
                SQL
            ),
            $this->buildInvalidRowsRule(
                entity: 'open_finance_connection_accounts',
                table: 'layout_empresa',
                rule: 'invalid_account_uuid',
                field: 'id',
                invalidRowsSql: <<<SQL
                    SELECT COALESCE(layout_empresa.account, '<null>') AS value, COALESCE(layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
                    WHERE fk_empresa <> 0
                      AND fk_layoutimp <> 0
                      AND layout_empresa.hab_belvo = true
                      AND itemid IS NOT NULL
                      AND account IS NOT NULL
                      AND account !~ '{$uuidPattern}'
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'open_finance_connection_accounts',
                table: 'layout_empresa',
                rule: 'missing_open_finance_connection_reference',
                field: 'open_finance_connection_id',
                invalidRowsSql: <<<'SQL'
                    SELECT COALESCE(layout_empresa.itemid, '<null>') AS value, COALESCE(layout_empresa.account, layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
                    WHERE fk_empresa <> 0
                      AND fk_layoutimp <> 0
                      AND layout_empresa.hab_belvo = true
                      AND account IS NOT NULL
                      AND itemid IS NULL
                SQL
            ),
            $this->buildMissingReferenceRule(
                entity: 'open_finance_connection_accounts',
                table: 'layout_empresa',
                rule: 'missing_company_layout_reference',
                field: 'company_layout_id',
                invalidRowsSql: <<<'SQL'
                    SELECT layout_empresa.pk::text AS value, COALESCE(layout_empresa.account, layout_empresa.pk::text, '') AS legacy_id
                    FROM layout_empresa
                    LEFT JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1
                    WHERE fk_empresa <> 0
                      AND fk_layoutimp <> 0
                      AND layout_empresa.hab_belvo = true
                      AND itemid IS NOT NULL
                      AND account IS NOT NULL
                      AND layout.pk IS NULL
                SQL
            ),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function buildDuplicateRule(
        string $entity,
        string $table,
        string $rule,
        ?string $field,
        string $duplicateExpression,
        string $tableExpression,
        string $whereClause,
        string $legacyIdsExpression,
        string $extraHavingClause = ''
    ): array {
        $baseGrouped = <<<SQL
            SELECT
                {$duplicateExpression} AS duplicate_value,
                COUNT(*) AS duplicate_count,
                STRING_AGG({$legacyIdsExpression}, ',' ORDER BY {$legacyIdsExpression}) AS legacy_ids
            FROM {$tableExpression}
            WHERE {$whereClause}
            GROUP BY {$duplicateExpression}
            HAVING COUNT(*) > 1 {$extraHavingClause}
        SQL;

        return $this->buildGroupedRule($entity, $table, $rule, $field, $baseGrouped);
    }

    /**
     * @return array<string, string|null>
     */
    private function buildInvalidRowsRule(
        string $entity,
        string $table,
        string $rule,
        ?string $field,
        string $invalidRowsSql
    ): array {
        $baseGrouped = <<<SQL
            SELECT
                value AS duplicate_value,
                COUNT(*) AS duplicate_count,
                STRING_AGG(legacy_id, ',' ORDER BY legacy_id) AS legacy_ids
            FROM (
                {$invalidRowsSql}
            ) invalid_rows
            GROUP BY value
        SQL;

        return $this->buildGroupedRule($entity, $table, $rule, $field, $baseGrouped);
    }

    /**
     * @return array<string, string|null>
     */
    private function buildMissingReferenceRule(
        string $entity,
        string $table,
        string $rule,
        ?string $field,
        string $invalidRowsSql
    ): array {
        return $this->buildInvalidRowsRule($entity, $table, $rule, $field, $invalidRowsSql);
    }

    /**
     * @return array<string, string|null>
     */
    private function buildGroupedRule(
        string $entity,
        string $table,
        string $rule,
        ?string $field,
        string $baseGrouped
    ): array {
        $sampleGroups = self::SAMPLE_GROUP_LIMIT;
        $sampleLegacyIds = self::SAMPLE_LEGACY_IDS_LIMIT;

        return [
            'entity' => $entity,
            'table' => $table,
            'rule' => $rule,
            'field' => $field,
            'samples_sql' => <<<SQL
                WITH grouped AS (
                    {$baseGrouped}
                )
                SELECT
                    duplicate_value AS value,
                    duplicate_count,
                    (
                        SELECT STRING_AGG(id_value, ',' ORDER BY id_value)
                        FROM (
                            SELECT id_value
                            FROM UNNEST(STRING_TO_ARRAY(grouped.legacy_ids, ',')) AS id_value
                            LIMIT {$sampleLegacyIds}
                        ) ids
                    ) AS legacy_ids
                FROM grouped
                ORDER BY duplicate_count DESC, duplicate_value ASC NULLS LAST
                LIMIT {$sampleGroups}
            SQL,
            'totals_sql' => <<<SQL
                WITH grouped AS (
                    {$baseGrouped}
                )
                SELECT
                    COUNT(*)::int AS total_groups,
                    COALESCE(SUM(duplicate_count), 0)::int AS total_records
                FROM grouped
            SQL,
        ];
    }
}
