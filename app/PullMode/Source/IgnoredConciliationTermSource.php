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

class IgnoredConciliationTermSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'ignored_conciliation_terms';
    }

    public function targetTable(): string
    {
        return 'ignored_conciliation_terms';
    }

    public function fkMap(): array
    {
        return [
            'legacy_layout_id' => 'layouts',
        ];
    }

    public function countSql(): ?string
    {
        return 'SELECT COUNT(*) AS count FROM layout_despresados WHERE fk_layout <> 0 AND historico IS NOT NULL';
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                pk                                                      AS legacy_id,
                fk_layout                                               AS legacy_layout_id,
                historico                                               AS history,
                fornecedor                                              AS client_supplier,
                CASE WHEN historico IS NULL THEN FALSE ELSE TRUE END    AS consider_history,
                CASE WHEN fornecedor IS NULL THEN FALSE ELSE TRUE END   AS consider_client_supplier,
                inclusao                                                AS created_at,
                alteracao                                               AS updated_at
            FROM layout_despresados
            WHERE fk_layout <> 0
              AND historico IS NOT NULL
            ORDER BY inclusao
        SQL;
    }
}
