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
 * Source legada → `rules_sharings`. Raiz de FK.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class RulesSharingSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'rules_sharings';
    }

    public function targetTable(): string
    {
        return 'rules_sharings';
    }

    public function fkMap(): array
    {
        return [
            'legacy_contract_id' => 'contracts',
        ];
    }

    public function countSql(): ?string
    {
        return 'SELECT COUNT(*) AS count FROM plano_contas WHERE pk <> 0';
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                pk                  AS legacy_id,
                pk                  AS code,
                nome                AS name,
                CURRENT_DATABASE()  AS legacy_contract_id
            FROM plano_contas
            WHERE pk <> 0
              AND pk > COALESCE(NULLIF(:last_id, '')::BIGINT, 0)
            ORDER BY pk ASC
            LIMIT :limit
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'code' => 'required|integer',
            'name' => 'required|string|max:30',
        ];
    }
}
