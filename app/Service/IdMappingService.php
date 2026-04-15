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
use Hyperf\DbConnection\Db;
use Ramsey\Uuid\Uuid;
use RuntimeException;

use function Hyperf\Support\now;

class IdMappingService
{
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
        }
    }

    public function resolve(string $entity, int|string $legacyId, string $contractId): ?string
    {
        $mapping = MigrationIdMapping::query()
            ->where('entity', $entity)
            ->where('legacy_id', $legacyId)
            ->where('contract_id', $contractId)
            ->first();

        return $mapping?->new_id;
    }

    public function resolveMany(string $entity, array $legacyIds, string $contractId): array
    {
        return MigrationIdMapping::query()
            ->where('entity', $entity)
            ->whereIn('legacy_id', $legacyIds)
            ->where('contract_id', $contractId)
            ->pluck('new_id', 'legacy_id')
            ->toArray();
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
}
