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

class ConfrontationConciliationSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'confrontation_conciliations';
    }

    public function targetTable(): string
    {
        return 'confrontation_conciliations';
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function idStrategy(): string
    {
        return 'uuid7';
    }

    public function normalizeStrings(): bool
    {
        return false;
    }

    public function fkMap(): array
    {
        return [
            'legacy_confrontation_id' => 'confrontations',
            'legacy_confrontation_records_bank' => 'confrontation_records',
            'legacy_confrontation_records_financial' => 'confrontation_records',
        ];
    }

    public function countSql(): ?string
    {
        return <<<'SQL'
            SELECT COUNT(*) AS count
            FROM confrontos_itens
            JOIN confrontos_itens bank ON confrontos_itens.fk_confrontos_item = bank.pk
            WHERE confrontos_itens.fk_layoutimp <> 0
              AND confrontos_itens.created_at > NOW() - INTERVAL '60 days'
              AND confrontos_itens.tipo = 'F'
        SQL;
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                confrontos_itens.pk                         AS legacy_id,
                confrontos_itens.fk_confrontos              AS legacy_confrontation_id,
                bank.pk                                     AS legacy_confrontation_records_bank,
                confrontos_itens.pk                         AS legacy_confrontation_records_financial
            FROM confrontos_itens
            JOIN confrontos_itens bank ON confrontos_itens.fk_confrontos_item = bank.pk
            WHERE confrontos_itens.fk_layoutimp <> 0
              AND confrontos_itens.created_at > NOW() - INTERVAL '60 days'
              AND confrontos_itens.tipo = 'F'
        SQL;
    }
}
