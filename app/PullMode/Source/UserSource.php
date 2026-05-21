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

use Hyperf\DbConnection\Db;

/**
 * Source legada → `users`. Raiz de FK; o pivot contract_users liga ao contrato.
 *
 * Atenção: campo `password` é re-hashado (bcrypt) em recordPrepPrepare(),
 * então o SELECT deve trazer a senha em texto claro OU já hashada — se já vier
 * hashada, sobrescreva transformRow() para evitar dupla bcrypt.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class UserSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'users';
    }

    public function targetTable(): string
    {
        return 'users';
    }

    public function fkMap(): array
    {
        return [];
    }

    public function hasContractId(): bool
    {
        return false;
    }

    public function countSql(): ?string
    {
        return "SELECT COUNT(*) AS count FROM usuarios WHERE email <> 'suporte@integradorcontabil.net.br'";
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                pk AS legacy_id,
                nome AS name,
                TRIM(email) AS email,
                senha AS password,
                CASE inativo WHEN true THEN false ELSE true END AS status
            FROM usuarios
            WHERE email <> 'suporte@integradorcontabil.net.br'
        SQL;
    }

    /**
     * Executa a query de usuários sem placeholders de paginação.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paginate(string $connection, ?string $_lastId, int $_limit): array
    {
        $rows = Db::connection($connection)->select($this->sql());

        return array_map(static fn ($r) => (array) $r, $rows);
    }

    public function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:4',
            'is_admin' => 'nullable|boolean',
            'is_internal' => 'nullable|boolean',
            'status' => 'nullable|boolean',
        ];
    }
}
