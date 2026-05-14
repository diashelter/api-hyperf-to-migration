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
    private const SIDE_MAP = [
        'D' => 'debit',
        'C' => 'credit',
    ];

    private const FIELD_MAP = [
        'valor' => 'value',
        'juros' => 'fees',
        'multa' => 'fine',
        'desconto' => 'discount',
        'outros' => 'others',
        'devolução' => 'refunds',
        'devolucao' => 'refunds',
        'tarifas' => 'rates',
        'taxas' => 'rates',
    ];

    private const FIELD_PREFIXES = [
        'value',
        'fees',
        'fine',
        'discount',
        'others',
        'refunds',
        'rates',
    ];

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

    public function paginationKey(): string
    {
        return 'legacy_company_layout_id';
    }

    public function fkMap(): array
    {
        return [
            'legacy_company_layout_id' => 'company_layout',
        ];
    }

    public function hasContractId(): bool
    {
        return false;
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
              AND fk_empresa <> 0
              AND fk_layoutimp <> 0
              AND pk > COALESCE(CAST(NULLIF(REGEXP_REPLACE(:last_id, '^LF-', ''), '') AS INTEGER), 0)
            ORDER BY pk
            LIMIT :limit
        SQL;
    }

    public function countSql(): ?string
    {
        return 'SELECT COUNT(*) AS count FROM layout_empresa WHERE conta_fixa_hab = true AND fk_empresa <> 0 AND fk_layoutimp <> 0';
    }

    public function transformRow(array $row, string $contractId): array
    {
        $model = $this->decodeFixedAccountsModel($row['contas_fixas_modelo'] ?? null);

        unset($row['contas_fixas_modelo']);
        $row += $this->emptyNormalizedFields();

        foreach (self::SIDE_MAP as $legacySide => $targetSide) {
            $entries = $model[$legacySide] ?? [];

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $prefix = $this->targetPrefix($entry['campo'] ?? null);

                if ($prefix === null) {
                    continue;
                }

                $this->setNormalizedField($row, "{$prefix}_{$targetSide}", $entry['conta'] ?? null);
                $this->setNormalizedField($row, "{$prefix}_code_history_{$targetSide}", $entry['cod_hist'] ?? null);
                $this->setNormalizedField($row, "{$prefix}_history_{$targetSide}", $entry['hist_person'] ?? null);
            }
        }

        return $row;
    }

    public function validationRules(): array
    {
        $rules = [
            'legacy_id' => 'required|string|max:255',
            'legacy_company_layout_id' => 'required|string|max:255',
            'bank_account' => 'nullable|string|max:100',
        ];

        foreach ($this->normalizedFieldNames() as $field) {
            $rules[$field] = str_contains($field, '_code_history_')
                ? 'nullable|string|max:100'
                : 'nullable|string';
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFixedAccountsModel(mixed $model): array
    {
        if (! is_string($model) || trim($model) === '') {
            return [];
        }

        $decoded = json_decode($model, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function targetPrefix(mixed $field): ?string
    {
        if (! is_scalar($field)) {
            return null;
        }

        $normalized = mb_strtolower(trim((string) $field));

        return self::FIELD_MAP[$normalized] ?? null;
    }

    private function blankToNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function setNormalizedField(array &$row, string $field, mixed $value): void
    {
        $normalizedValue = $this->blankToNull($value);

        if ($normalizedValue !== null || ! isset($row[$field])) {
            $row[$field] = $normalizedValue;
        }
    }

    /**
     * @return array<string, null>
     */
    private function emptyNormalizedFields(): array
    {
        return array_fill_keys($this->normalizedFieldNames(), null);
    }

    /**
     * @return array<int, string>
     */
    private function normalizedFieldNames(): array
    {
        $fields = [];

        foreach (self::SIDE_MAP as $side) {
            foreach (self::FIELD_PREFIXES as $prefix) {
                $fields[] = "{$prefix}_{$side}";
                $fields[] = "{$prefix}_code_history_{$side}";
                $fields[] = "{$prefix}_history_{$side}";
            }
        }

        return $fields;
    }
}
