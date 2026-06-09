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
                pcontasconc_item.pk             AS legacy_id,
                pcontasconc_item.fk_pcontasconc AS legacy_plan_id,
                pcontasconc_item.nome_conta     AS "name",
                pcontasconc_item.codigo         AS complete_account,
                pcontasconc_item.codigo_redu    AS reduced_account,
                pcontasconc_item.tipo           AS "type",
                pcontasconc_item.origem         AS origin
            FROM pcontasconc_item
            INNER JOIN pcontasconc ON pcontasconc.pk = pcontasconc_item.fk_pcontasconc
            WHERE pcontasconc_item.fk_pcontasconc IS NOT NULL
              AND pcontasconc_item.fk_pcontasconc <> 0
              AND pcontasconc_item.pk > COALESCE(CAST(NULLIF(:last_id, '') AS INTEGER), 0)
            ORDER BY pcontasconc_item.pk
            LIMIT :limit
        SQL;
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(*) AS count
            FROM pcontasconc_item
            INNER JOIN pcontasconc ON pcontasconc.pk = pcontasconc_item.fk_pcontasconc
            WHERE pcontasconc_item.fk_pcontasconc IS NOT NULL
              AND pcontasconc_item.fk_pcontasconc <> 0
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
