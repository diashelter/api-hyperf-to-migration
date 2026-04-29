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
 * Source legada → `plan_items`. FK: plans.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class PlanItemSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'plan_items';
    }

    public function targetTable(): string
    {
        return 'plan_items';
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function fkMap(): array
    {
        return [
            'legacy_plan_id' => 'plans',
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
                pk                  AS legacy_id,
                fk_pcontasconc      AS legacy_plan_id,
                nome_conta          AS "name",
                codigo              AS complete_account,
                codigo_redu         AS reduced_account,
                tipo                AS "type",
                origem              AS origin
            FROM pcontasconc_item
            WHERE pk > COALESCE(CAST(NULLIF(:last_id, '') AS INTEGER), 0)
            ORDER BY pk
            LIMIT :limit
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'name' => 'required|string|max:70',
            'complete_account' => 'nullable|string|max:20',
            'reduced_account' => 'nullable|string|max:20',
            'type' => 'nullable|string|max:50',
            'origin' => 'nullable|in:C,D,I',
        ];
    }
}
