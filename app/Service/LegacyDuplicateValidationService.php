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
        $sampleGroups = self::SAMPLE_GROUP_LIMIT;
        $sampleLegacyIds = self::SAMPLE_LEGACY_IDS_LIMIT;

        return [
            $this->buildRule(
                entity: 'users',
                table: 'usuarios',
                rule: 'duplicate_email',
                field: 'email',
                duplicateExpression: 'LOWER(TRIM(email))',
                tableExpression: 'usuarios',
                whereClause: "email IS NOT NULL AND TRIM(email) <> '' AND LOWER(TRIM(email)) <> 'suporte@integradorcontabil.net.br'",
                legacyIdsExpression: "COALESCE(pk::text, '')",
                sampleGroups: $sampleGroups,
                sampleLegacyIds: $sampleLegacyIds
            ),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function buildRule(
        string $entity,
        string $table,
        string $rule,
        ?string $field,
        string $duplicateExpression,
        string $tableExpression,
        string $whereClause,
        string $legacyIdsExpression,
        int $sampleGroups,
        int $sampleLegacyIds,
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
