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
 * Source legada → `user_company_restrictions`. FKs: users, companies.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class UserCompanyRestrictionSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'user_company_restrictions';
    }

    public function targetTable(): string
    {
        return 'user_company_restrictions';
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function fkMap(): array
    {
        return [
            'legacy_user_id' => 'users',
            'legacy_company_id' => 'companies',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                id              AS legacy_id,
                usuario_id      AS legacy_user_id,
                empresa_id      AS legacy_company_id
            FROM usuario_empresa_restricao
            WHERE id::text > :last_id
            ORDER BY id ASC
            LIMIT :limit
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'legacy_user_id' => 'required|string|max:255',
            'legacy_company_id' => 'required|string|max:255',
        ];
    }
}
