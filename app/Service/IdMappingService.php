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

use App\Model\MigrationIdMapping;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

use function Hyperf\Support\env;
use function Hyperf\Support\now;

class IdMappingService
{
    private const CACHE_KEY = '__idmapping_cache__';

    /**
     * Sentinel usado para diferenciar "chave não cacheada" de
     * "chave cacheada com valor null" (miss confirmado no DB).
     */
    private const CACHE_MISS = "\0__NOT_CACHED__\0";

    public function store(string $entity, string $legacyId, string $newId, string $contractId, ?string $batchId = null): void
    {
        MigrationIdMapping::query()->updateOrCreate(
            [
                'entity' => $entity,
                'legacy_id' => $legacyId,
                'contract_id' => $contractId,
            ],
            [
                'id' => Uuid::uuid7()->toString(),
                'new_id' => $newId,
                'migration_batch_id' => $batchId,
            ]
        );

        if ($this->shouldCacheEntity($entity)) {
            $this->cachePut($contractId, $entity, (string) $legacyId, $newId);
        }
    }

    public function storeBatch(string $entity, array $mappings, string $contractId, ?string $batchId = null): void
    {
        $records = [];
        $now = now()->toDateTimeString();

        foreach ($mappings as $legacyId => $newId) {
            $records[] = [
                'id' => Uuid::uuid7()->toString(),
                'entity' => $entity,
                'legacy_id' => (string) $legacyId,
                'new_id' => $newId,
                'contract_id' => $contractId,
                'migration_batch_id' => $batchId,
                'created_at' => $now,
            ];
        }

        if (! empty($records)) {
            $mappingChunkSize = max(1, (int) env('MIGRATION_MAPPING_CHUNK_SIZE', 1000));
            $chunks = array_chunk($records, $mappingChunkSize);
            $maxCoroutines = max(1, (int) env('MIGRATION_MAPPING_COROUTINES', 4));

            if (count($chunks) === 1 || $maxCoroutines === 1) {
                foreach ($chunks as $chunk) {
                    Db::table('migration_id_mappings')->upsert(
                        $chunk,
                        ['entity', 'legacy_id', 'contract_id'],
                        ['new_id', 'migration_batch_id']
                    );
                }
            } else {
                $parallel = new Parallel($maxCoroutines);
                foreach ($chunks as $index => $chunk) {
                    $parallel->add(function () use ($chunk, $index) {
                        try {
                            Db::table('migration_id_mappings')->upsert(
                                $chunk,
                                ['entity', 'legacy_id', 'contract_id'],
                                ['new_id', 'migration_batch_id']
                            );
                            return ['ok' => true, 'index' => $index];
                        } catch (Throwable $e) {
                            return ['ok' => false, 'index' => $index, 'error' => $e->getMessage()];
                        }
                    });
                }

                $results = $parallel->wait();
                foreach ($results as $r) {
                    if (! $r['ok']) {
                        // Idempotência: re-lançar para que o caller possa decidir.
                        throw new RuntimeException(
                            "storeBatch chunk {$r['index']} failed: {$r['error']}"
                        );
                    }
                }
            }

            if ($this->shouldCacheEntity($entity)) {
                // Só popula cache após confirmação de todos os chunks.
                foreach ($mappings as $legacyId => $newId) {
                    $this->cachePut($contractId, $entity, (string) $legacyId, $newId);
                }
            }
        }
    }

    public function resolve(string $entity, int|string $legacyId, string $contractId): ?string
    {
        $key = (string) $legacyId;
        $useCache = $this->shouldCacheEntity($entity);

        if ($useCache) {
            $cached = $this->cacheGet($contractId, $entity, $key);

            if ($cached !== self::CACHE_MISS) {
                return $cached;
            }
        }

        $mapping = MigrationIdMapping::query()
            ->where('entity', $entity)
            ->where('legacy_id', $legacyId)
            ->where('contract_id', $contractId)
            ->first();

        $newId = $mapping?->new_id;
        if ($useCache) {
            $this->cachePut($contractId, $entity, $key, $newId);
        }

        return $newId;
    }

    public function resolveMany(string $entity, array $legacyIds, string $contractId): array
    {
        $result = [];
        $toFetch = [];
        $useCache = $this->shouldCacheEntity($entity);

        foreach ($legacyIds as $legacyId) {
            $key = (string) $legacyId;
            if (! $useCache) {
                $toFetch[$key] = $legacyId;
                continue;
            }

            $cached = $this->cacheGet($contractId, $entity, $key);

            if ($cached === self::CACHE_MISS) {
                $toFetch[$key] = $legacyId;
                continue;
            }

            if ($cached !== null) {
                $result[$key] = $cached;
            }
        }

        if (! empty($toFetch)) {
            $fetched = MigrationIdMapping::query()
                ->where('entity', $entity)
                ->whereIn('legacy_id', array_values($toFetch))
                ->where('contract_id', $contractId)
                ->pluck('new_id', 'legacy_id')
                ->toArray();

            foreach ($toFetch as $key => $legacyId) {
                $key = (string) $key;
                $newId = $fetched[$legacyId] ?? $fetched[$key] ?? null;
                if ($useCache) {
                    $this->cachePut($contractId, $entity, $key, $newId);
                }
                if ($newId !== null) {
                    $result[$key] = $newId;
                }
            }
        }

        return $result;
    }

    public function prewarm(string $entity, array $legacyIds, string $contractId): void
    {
        if (empty($legacyIds)) {
            return;
        }

        $this->resolveMany($entity, $legacyIds, $contractId);
    }

    /**
     * Pré-aquece o cache para múltiplas entidades em UMA única query batch,
     * eliminando 1 round-trip por FK do registro. O cache resultante é
     * indexado por (contract_id, entity, legacy_id), idêntico a `prewarm()`.
     *
     * @param array<string, array<int, int|string>> $legacyIdsByEntity entity => [legacyId, ...]
     */
    public function prewarmMulti(array $legacyIdsByEntity, string $contractId): void
    {
        $toFetch = [];

        foreach ($legacyIdsByEntity as $entity => $legacyIds) {
            if (! $this->shouldCacheEntity($entity)) {
                continue;
            }

            foreach ($legacyIds as $legacyId) {
                $key = (string) $legacyId;
                if ($this->cacheGet($contractId, $entity, $key) === self::CACHE_MISS) {
                    $toFetch[$entity][$key] = $key;
                }
            }
        }

        if (empty($toFetch)) {
            return;
        }

        $query = MigrationIdMapping::query()->where('contract_id', $contractId);
        $query->where(function ($q) use ($toFetch) {
            foreach ($toFetch as $entity => $ids) {
                $q->orWhere(function ($qq) use ($entity, $ids) {
                    $qq->where('entity', $entity)->whereIn('legacy_id', array_values($ids));
                });
            }
        });

        $rows = $query->get(['entity', 'legacy_id', 'new_id']);

        $found = [];
        foreach ($rows as $row) {
            $entity = (string) $row->entity;
            $legacyId = (string) $row->legacy_id;
            $found[$entity][$legacyId] = (string) $row->new_id;
            $this->cachePut($contractId, $entity, $legacyId, (string) $row->new_id);
        }

        // Cacheia também os misses confirmados para evitar nova ida ao banco.
        foreach ($toFetch as $entity => $ids) {
            foreach ($ids as $key) {
                if (! isset($found[$entity][$key])) {
                    $this->cachePut($contractId, $entity, $key, null);
                }
            }
        }
    }

    public function resolveOrFail(string $entity, string $legacyId, string $contractId): string
    {
        $newId = $this->resolve($entity, $legacyId, $contractId);

        if ($newId === null) {
            throw new RuntimeException(
                "ID mapping not found for entity '{$entity}' with legacy_id '{$legacyId}'"
            );
        }

        return $newId;
    }

    private function shouldCacheEntity(string $entity): bool
    {
        static $skip = null;

        if ($skip === null) {
            $raw = (string) env('MIGRATION_ID_MAPPING_CACHE_SKIP', 'rules,import_records');
            $entities = array_filter(array_map(
                static fn (string $value): string => trim($value),
                explode(',', $raw)
            ));
            $skip = array_fill_keys($entities, true);
        }

        return ! isset($skip[$entity]);
    }

    private function cacheGet(string $contractId, string $entity, string $key): mixed
    {
        $cache = Context::get(self::CACHE_KEY);

        if (! is_array($cache)) {
            return self::CACHE_MISS;
        }

        if (! isset($cache[$contractId][$entity]) || ! array_key_exists($key, $cache[$contractId][$entity])) {
            return self::CACHE_MISS;
        }

        return $cache[$contractId][$entity][$key];
    }

    private function cachePut(string $contractId, string $entity, string $key, ?string $newId): void
    {
        $cache = Context::get(self::CACHE_KEY);

        if (! is_array($cache)) {
            $cache = [];
        }

        $cache[$contractId][$entity][$key] = $newId;
        Context::set(self::CACHE_KEY, $cache);
    }
}
