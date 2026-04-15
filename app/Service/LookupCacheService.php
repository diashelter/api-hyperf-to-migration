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

namespace App\Service;

use Hyperf\DbConnection\Db;
use InvalidArgumentException;

use function Hyperf\Support\env;
use function Hyperf\Support\now;

class LookupCacheService
{
    /**
     * Entidades suportadas e suas tabelas/colunas no conciliador_web.
     *
     * Formato: 'entity' => ['table' => '...', 'id_col' => '...', 'label_col' => '...']
     */
    private const ENTITIES = [
        'roles' => ['table' => 'roles', 'id_col' => 'id', 'label_col' => 'name'],
        'status' => ['table' => 'status', 'id_col' => 'id', 'label_col' => 'name'],
        'layouts_admin' => ['table' => 'layouts_admin', 'id_col' => 'id', 'label_col' => 'code'],
        'permissions' => ['table' => 'permissions', 'id_col' => 'id', 'label_expr' => "module || ':' || name"],
    ];

    /**
     * Busca todos os registros de uma entidade do conciliador_web
     * e faz upsert na tabela local lookup_cache.
     */
    public function seed(string $entity): int
    {
        if (! isset(self::ENTITIES[$entity])) {
            throw new InvalidArgumentException("Entity '{$entity}' is not supported for lookup caching.");
        }

        $config = self::ENTITIES[$entity];
        $env = $this->getEnvironment();
        $now = now()->toDateTimeString();

        $selectExpr = isset($config['label_expr'])
            ? "{$config['id_col']} as external_id, {$config['label_expr']} as label"
            : "{$config['id_col']} as external_id, {$config['label_col']} as label";

        $rows = Db::connection('conciliador_web')
            ->table($config['table'])
            ->selectRaw($selectExpr)
            ->get()
            ->toArray();

        if (empty($rows)) {
            return 0;
        }

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'entity' => $entity,
                'external_id' => (string) $row->external_id,
                'label' => $row->label ?? null,
                'payload' => null,
                'environment' => $env,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            Db::table('lookup_cache')->upsert(
                $chunk,
                ['entity', 'external_id', 'environment'],
                ['label', 'payload', 'updated_at']
            );
        }

        return count($records);
    }

    /**
     * Busca todas as entidades suportadas e popula o lookup_cache.
     * Retorna array com contagem por entidade.
     */
    public function seedAll(): array
    {
        $results = [];
        foreach (array_keys(self::ENTITIES) as $entity) {
            $results[$entity] = $this->seed($entity);
        }
        return $results;
    }

    /**
     * Resolve o external_id de uma entidade para seu valor real no conciliador_web.
     * Busca apenas no banco local (lookup_cache) — sem cross-DB em runtime.
     *
     * Retorna null se não encontrado.
     */
    public function resolve(string $entity, int|string $label): ?string
    {
        $row = Db::table('lookup_cache')
            ->where('entity', $entity)
            ->where('label', (string) $label)
            ->where('environment', $this->getEnvironment())
            ->value('external_id');

        return $row !== null ? (string) $row : null;
    }

    /**
     * Resolve múltiplos external_ids de uma vez (bulk).
     * Retorna array indexado por external_id → external_id (confirmação de existência).
     */
    public function resolveMany(string $entity, array $externalIds): array
    {
        $stringIds = array_map('strval', $externalIds);

        return Db::table('lookup_cache')
            ->where('entity', $entity)
            ->whereIn('external_id', $stringIds)
            ->where('environment', $this->getEnvironment())
            ->pluck('external_id', 'external_id')
            ->toArray();
    }

    /**
     * Retorna todos os registros de uma entidade como array [external_id => label].
     * Útil para exibição ou debug.
     */
    public function all(string $entity): array
    {
        return Db::table('lookup_cache')
            ->where('entity', $entity)
            ->where('environment', $this->getEnvironment())
            ->pluck('label', 'external_id')
            ->toArray();
    }

    /**
     * Lista as entidades suportadas.
     */
    public static function supportedEntities(): array
    {
        return array_keys(self::ENTITIES);
    }

    private function getEnvironment(): string
    {
        return env('APP_ENV', 'local');
    }
}
