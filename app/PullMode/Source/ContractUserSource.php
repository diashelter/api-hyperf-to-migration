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
 * Source legada → `contract_user` (pivot N:N).
 *
 * Special handler: pivot table sem PK simples, sem id_mappings, com upsert.
 * O EntityMigrator delega para runSpecialHandler() — implementação pendente.
 */
class ContractUserSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'contract_users';
    }

    public function targetTable(): string
    {
        return 'contract_user';
    }

    public function fkMap(): array
    {
        return [
            'legacy_user_id' => 'users',
            'legacy_contract_id' => 'contracts',
        ];
    }

    public function idStrategy(): string
    {
        // Pivot não tem id próprio.
        return 'uuid4';
    }

    public function normalizeStrings(): bool
    {
        return false;
    }

    public function specialHandler(): ?string
    {
        return 'contract_users_pivot';
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                'CU-' || pk AS legacy_id,
                pk AS legacy_user_id,
                CURRENT_DATABASE() AS legacy_contract_id,
                CASE administrador WHEN 1 THEN 'owner' ELSE 'user' END AS legacy_role_id,
                CASE administrador WHEN 1 THEN true ELSE false END AS contract_admin
            FROM usuarios
            WHERE email <> 'suporte@integradorcontabil.net.br'
            ORDER BY pk
        SQL;
    }
}
