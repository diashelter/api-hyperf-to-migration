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
use Hyperf\DbConnection\Db;
use Ramsey\Uuid\Uuid;
use RuntimeException;

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
                'id' => Uuid::uuid4()->toString(),
                'new_id' => $newId,
                'migration_batch_id' => $batchId,
            ]
        );

        $this->cachePut($contractId, $entity, (string) $legacyId, $newId);
    }

    public function storeBatch(string $entity, array $mappings, string $contractId, ?string $batchId = null): void
    {
        $records = [];
        $now = now()->toDateTimeString();

        foreach ($mappings as $legacyId => $newId) {
            $records[] = [
                'id' => Uuid::uuid4()->toString(),
                'entity' => $entity,
                'legacy_id' => (string) $legacyId,
                'new_id' => $newId,
                'contract_id' => $contractId,
                'migration_batch_id' => $batchId,
                'created_at' => $now,
            ];
        }

        if (! empty($records)) {
            $chunks = array_chunk($records, 500);
            foreach ($chunks as $chunk) {
                Db::table('migration_id_mappings')->upsert(
                    $chunk,
                    ['entity', 'legacy_id', 'contract_id'],
                    ['new_id', 'migration_batch_id']
                );
            }

            foreach ($mappings as $legacyId => $newId) {
                $this->cachePut($contractId, $entity, (string) $legacyId, $newId);
            }
        }
    }

    public function resolve(string $entity, int|string $legacyId, string $contractId): ?string
    {
        $key = (string) $legacyId;
        $cached = $this->cacheGet($contractId, $entity, $key);

        if ($cached !== self::CACHE_MISS) {
            return $cached;
        }

        $mapping = MigrationIdMapping::query()
            ->where('entity', $entity)
            ->where('legacy_id', $legacyId)
            ->where('contract_id', $contractId)
            ->first();

        $newId = $mapping?->new_id;
        $this->cachePut($contractId, $entity, $key, $newId);

        return $newId;
    }

    public function resolveMany(string $entity, array $legacyIds, string $contractId): array
    {
        $result = [];
        $toFetch = [];

        foreach ($legacyIds as $legacyId) {
            $key = (string) $legacyId;
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
                $this->cachePut($contractId, $entity, $key, $newId);
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
