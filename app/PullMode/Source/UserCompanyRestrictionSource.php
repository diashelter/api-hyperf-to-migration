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

    public function countSql(): ?string
    {
        return 'SELECT COUNT(*) AS count FROM usuario_empresas';
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                pk          AS legacy_id,
                fk_usuario  AS legacy_user_id,
                fk_empresa  AS legacy_company_id
            FROM usuario_empresas
            WHERE pk > COALESCE(CAST(NULLIF(:last_id, '') AS INTEGER), 0)
            ORDER BY pk ASC
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
