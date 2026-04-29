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
use function Hyperf\Support\env;

/**
 * Source legada da tabela `contrato` → `contracts`.
 *
 * Particularidades:
 *   - legacy_id = CURRENT_DATABASE(): há exatamente UMA linha por banco legado
 *     (o próprio contrato do cliente). Keyset pagination não se aplica.
 *   - paginate() é sobrescrito para um único SELECT sem cursores.
 *   - Na retomada (lastId preenchido), paginate() retorna [] e o loop encerra
 *     imediatamente (filterDuplicates garantiria idempotência de qualquer forma).
 *   - legacy_status_contract ('ATIVO' / 'SUSPENSO') é mapeado para o campo de
 *     destino em transformRow() até que um LookupCacheService de pull-mode exista.
 */
class ContractSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'contracts';
    }

    public function targetTable(): string
    {
        return 'contracts';
    }

    public function fkMap(): array
    {
        return [];
    }

    /**
     * SQL sem cursores de paginação: retorna a linha única do contrato.
     * Nunca chamado diretamente pelo pipeline — paginate() é sobrescrito.
     */
    public function sql(): string
    {
        return <<<'SQL'
            SELECT
                CURRENT_DATABASE()  AS legacy_id,
                cnpj                AS cpf_cnpj,
                razaosocial         AS corporate_name,
                endereco            AS street,
                numero              AS number,
                bairro              AS neighborhood,
                cidade              AS city,
                complemento         AS complement,
                uf                  AS state,
                COALESCE(NULLIF(fantasia, ''), razaosocial) AS name,
                email               AS email,
                'company'           AS contractor_type,
                1000                AS company_count,
                CASE ativo
                    WHEN 'F' THEN 'SUSPENSO'
                    ELSE 'ATIVO'
                END                 AS legacy_status_contract,
                cep                 AS zipcode,
                false               AS is_approval,
                telefone            AS phone,
                CURRENT_DATABASE()  AS legacy_database_id,
                inclusao            AS created_at
            FROM contrato
            WHERE pk <> 0
            ORDER BY pk
        SQL;
    }

    /**
     * Singleton por banco: executa o SELECT completo na primeira chamada.
     * Se lastId já estiver preenchido (retomada), retorna [] — o registro já
     * foi processado e filterDuplicates rejeitaria de qualquer modo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paginate(string $connection, ?string $lastId, int $_limit): array
    {
        if ($lastId !== null) {
            return [];
        }

        $rows = Db::connection($connection)->select($this->sql());

        return array_map(static fn ($r) => (array) $r, $rows);
    }

    public function transformRow(array $row, string $_contractId): array
    {
        $legacyStatus = $row['legacy_status_contract'] ?? null;
        unset($row['legacy_status_contract']);

        $row['status_contract'] = $legacyStatus !== null
            ? Db::table('lookup_cache')
                ->where('entity', 'status')
                ->where('label', $legacyStatus)
                ->where('environment', env('APP_ENV', 'local'))
                ->value('external_id')
            : null;

        // PG retorna booleans como true/false — normalizar para PHP.
        $row['is_approval'] = (bool) ($row['is_approval'] ?? false);

        return $row;
    }

    public function validationRules(): array
    {
        return [
            'cpf_cnpj' => 'required|string|size:14',
            'corporate_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:15',
            'contractor_type' => 'required|in:individual,company',
            'company_count' => 'required|integer|min:1',
        ];
    }
}
