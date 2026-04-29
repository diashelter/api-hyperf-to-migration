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
 * Source legada → `plans`. Raiz de FK.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class PlanSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'plans';
    }

    public function targetTable(): string
    {
        return 'plans';
    }

    public function fkMap(): array
    {
        return [];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                pk                                                          AS legacy_id,
                pk                                                          AS code,
                nome                                                        AS name,
                CASE campo_retorno
                    WHEN 'Conta Completa' THEN 'COMPLETE'
                    ELSE 'REDUCED'
                END                                                         AS account_default
            FROM pcontasconc
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'name' => 'required|string|max:70',
            'account_default' => 'nullable|string|max:50',
            'code' => 'nullable|int',
        ];
    }
}
