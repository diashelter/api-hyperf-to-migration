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
 * Source legada → `permission_users`.
 *
 * Special handler: `user_permissions_delete` — não insere; apaga registros do
 * destino com base nos pares (user_id, company_id) que vêm do legado. Fluxo
 * implementado em fase posterior.
 */
class UserPermissionSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'user_permissions';
    }

    public function targetTable(): string
    {
        return 'permission_users';
    }

    public function fkMap(): array
    {
        return [
            'legacy_user_id' => 'users',
        ];
    }

    public function normalizeStrings(): bool
    {
        return false;
    }

    public function countSql(): ?string
    {
        return 'SELECT COUNT(*) AS count FROM usuario_permissao';
    }

    public function specialHandler(): ?string
    {
        return 'user_permissions_delete';
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                id          AS legacy_id,
                usuario_id  AS legacy_user_id
            FROM usuario_permissao
            WHERE id::text > :last_id
            ORDER BY id ASC
            LIMIT :limit
        SQL;
    }
}
