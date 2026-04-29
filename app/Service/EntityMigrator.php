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

use App\PullMode\Source\AbstractLegacySource;
use App\Trait\RecordPreparation;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Throwable;

use function Hyperf\Support\now;

class EntityMigrator
{
    use RecordPreparation;

    #[Inject]
    protected IdMappingService $idMappingService;

    #[Inject]
    protected LookupCacheService $lookupCacheService;

    #[Inject]
    protected ParallelInsertService $insertService;

    #[Inject]
    protected MigrationJobService $jobService;

    /**
     * Migra uma entidade do banco legado para `conciliador_web` em chunks via
     * keyset pagination. Toda metadata (SQL, transform, fkMap, idStrategy,
     * specialHandler) vem da Source — esse método é puro pipeline.
     *
     * Idempotente: na retomada lê `entity_progress.last_id` e continua de lá.
     */
    public function migrate(string $jobId, AbstractLegacySource $source, string $legacyConnection, string $contractId): array
    {
        $entity = $source->entity();

        if ($source->specialHandler() !== null) {
            return $this->runSpecialHandler($jobId, $source, $legacyConnection, $contractId);
        }

        $targetTable = $source->targetTable();
        $chunkSize = $source->chunkSize();
        $fkMap = $source->fkMap();
        $normalizeStrings = $source->normalizeStrings();
        $idStrategy = $source->idStrategy();
        $targetConnection = $source->targetConnection();
        $paginationKey = $source->paginationKey();

        $progress = $this->jobService->getEntityProgress($jobId, $entity);
        $lastId = $progress['last_id'] ?? null;

        $totals = [
            'inserted' => (int) ($progress['inserted'] ?? 0),
            'failed' => (int) ($progress['failed'] ?? 0),
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'last_id' => $lastId,
            'status' => 'processing',
            'started_at' => $progress['started_at'] ?? (string) now(),
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, [
            'status' => 'processing',
            'started_at' => $totals['started_at'],
            'last_id' => $lastId,
        ]);

        $resolveFn = function (array $record, string $cid) use ($fkMap): array {
            $record = $this->recordPrepResolveContractIdFK($this->idMappingService, $record, $cid);
            return $this->recordPrepResolveForeignKeysFromMap($this->idMappingService, $fkMap, $record, $cid);
        };

        try {
            while (true) {
                $rows = $source->paginate($legacyConnection, $lastId, $chunkSize);

                if (empty($rows)) {
                    break;
                }

                $batch = array_map(
                    static fn (array $row): array => $source->transformRow($row, $contractId),
                    $rows
                );

                [$batch, $skipped] = $this->recordPrepFilterDuplicates(
                    $this->idMappingService,
                    $entity,
                    $batch,
                    $contractId
                );

                $this->recordPrepPrefetchForeignKeys(
                    $this->idMappingService,
                    $fkMap,
                    $batch,
                    $contractId
                );

                [$prepared, $idMappings] = $this->recordPrepPrepare(
                    $batch,
                    $contractId,
                    $normalizeStrings,
                    $idStrategy,
                    $resolveFn
                );

                if (! empty($prepared)) {
                    $result = $this->insertService->insertBatch(
                        $targetTable,
                        $prepared,
                        0,
                        0,
                        $targetConnection
                    );

                    $totals['inserted'] += (int) $result['inserted'];
                    $totals['failed'] += (int) $result['failed'];

                    if (! empty($idMappings) && $result['inserted'] > 0) {
                        $this->idMappingService->storeBatch($entity, $idMappings, $contractId, $jobId);
                    }
                }

                $totals['skipped'] += count($skipped);

                $lastRow = end($rows);
                $lastId = isset($lastRow[$paginationKey]) ? (string) $lastRow[$paginationKey] : null;
                $totals['last_id'] = $lastId;

                $this->jobService->updateEntityProgress($jobId, $entity, [
                    'last_id' => $lastId,
                    'inserted' => $totals['inserted'],
                    'failed' => $totals['failed'],
                    'skipped' => $totals['skipped'],
                ]);

                if (count($rows) < $chunkSize) {
                    break;
                }
            }

            $totals['status'] = $totals['failed'] > 0 ? 'completed_with_errors' : 'completed';
        } catch (Throwable $e) {
            $totals['status'] = 'failed';
            $totals['error_message'] = $e->getMessage();
        }

        $totals['finished_at'] = (string) now();
        $this->jobService->updateEntityProgress($jobId, $entity, $totals);

        // Acumula no totals do job apenas o delta desta execução.
        $deltaInserted = $totals['inserted'] - (int) ($progress['inserted'] ?? 0);
        $deltaFailed = $totals['failed'] - (int) ($progress['failed'] ?? 0);
        $deltaSkipped = $totals['skipped'] - (int) ($progress['skipped'] ?? 0);
        $this->jobService->incrementTotals($jobId, $deltaInserted, $deltaFailed, $deltaSkipped);

        return $totals;
    }

    private function runSpecialHandler(
        string $jobId,
        AbstractLegacySource $source,
        string $legacyConnection,
        string $contractId
    ): array {
        $handler = (string) $source->specialHandler();

        if ($handler === 'contract_users_pivot') {
            return $this->handleContractUsersPivot($jobId, $source, $legacyConnection, $contractId);
        }

        if ($handler === 'user_permissions_delete') {
            return $this->handleUserPermissionsDelete($jobId, $source, $legacyConnection, $contractId);
        }

        $entity = $source->entity();
        $totals = [
            'status' => 'pending_special_handler',
            'special_handler' => $handler,
            'inserted' => 0,
            'failed' => 0,
            'skipped' => 0,
            'finished_at' => (string) now(),
            'message' => "Entity '{$entity}' requires special handler '{$handler}'; not implemented in pull-mode yet.",
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, $totals);

        return $totals;
    }

    /**
     * Pivot `contract_user` (N:N, sem PK, sem timestamps).
     *
     * Fluxo:
     *   1. Lê todos os vínculos do legado via ContractUserSource (singleton load).
     *   2. Resolve user_id e contract_id via IdMappingService.
     *   3. Resolve role_id pelo label ('owner'/'user') via LookupCacheService.
     *   4. insertOrIgnore() — sem storeBatch (pivot sem id próprio).
     */
    private function handleContractUsersPivot(
        string $jobId,
        AbstractLegacySource $source,
        string $legacyConnection,
        string $contractId
    ): array {
        $entity = $source->entity();

        $progress = $this->jobService->getEntityProgress($jobId, $entity);
        $lastId = $progress['last_id'] ?? null;

        $totals = [
            'inserted' => (int) ($progress['inserted'] ?? 0),
            'failed' => (int) ($progress['failed'] ?? 0),
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'last_id' => $lastId,
            'status' => 'processing',
            'started_at' => $progress['started_at'] ?? (string) now(),
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, [
            'status' => 'processing',
            'started_at' => $totals['started_at'],
            'last_id' => $lastId,
        ]);

        try {
            $rows = $source->paginate($legacyConnection, $lastId, $source->chunkSize());

            if (! empty($rows)) {
                $this->recordPrepPrefetchForeignKeys(
                    $this->idMappingService,
                    $source->fkMap(),
                    $rows,
                    $contractId
                );

                $records = [];
                $failed = 0;

                foreach ($rows as $row) {
                    $userId = ! empty($row['legacy_user_id'])
                        ? $this->idMappingService->resolve('users', (string) $row['legacy_user_id'], $contractId)
                        : null;

                    $resolvedContractId = ! empty($row['legacy_contract_id'])
                        ? $this->idMappingService->resolve('contracts', (string) $row['legacy_contract_id'], $contractId)
                        : null;

                    $roleId = ! empty($row['legacy_role_id'])
                        ? $this->lookupCacheService->resolve('roles', (string) $row['legacy_role_id'])
                        : null;

                    if ($userId === null || $resolvedContractId === null || $roleId === null) {
                        $failed++;
                        continue;
                    }

                    $records[] = [
                        'user_id' => $userId,
                        'contract_id' => $resolvedContractId,
                        'role_id' => $roleId,
                        'contract_admin' => (bool) ($row['contract_admin'] ?? false),
                    ];
                }

                $inserted = 0;
                $skipped = 0;

                if (! empty($records)) {
                    $connection = Db::connection('conciliador_web');
                    $connection->beginTransaction();
                    try {
                        $inserted = $connection->table('contract_user')->insertOrIgnore($records);
                        $connection->commit();
                        $skipped = count($records) - $inserted;
                    } catch (Throwable $e) {
                        $connection->rollBack();
                        $failed += count($records);
                        throw $e;
                    }
                }

                $lastRow = end($rows);
                $lastId = isset($lastRow[$source->paginationKey()])
                    ? (string) $lastRow[$source->paginationKey()]
                    : null;

                $totals['inserted'] += $inserted;
                $totals['failed'] += $failed;
                $totals['skipped'] += $skipped;
                $totals['last_id'] = $lastId;
            }

            $totals['status'] = $totals['failed'] > 0 ? 'completed_with_errors' : 'completed';
        } catch (Throwable $e) {
            $totals['status'] = 'failed';
            $totals['error_message'] = $e->getMessage();
        }

        $totals['finished_at'] = (string) now();
        $this->jobService->updateEntityProgress($jobId, $entity, $totals);

        $deltaInserted = $totals['inserted'] - (int) ($progress['inserted'] ?? 0);
        $deltaFailed = $totals['failed'] - (int) ($progress['failed'] ?? 0);
        $deltaSkipped = $totals['skipped'] - (int) ($progress['skipped'] ?? 0);
        $this->jobService->incrementTotals($jobId, $deltaInserted, $deltaFailed, $deltaSkipped);

        return $totals;
    }

    /**
     * Apaga de `permission_users` todos os registros cujos user_ids vêm da
     * tabela `usuario_permissao` do legado, filtrados pelo contract_id resolvido.
     * Idempotente: DELETE WHERE não falha se os registros já foram removidos.
     */
    private function handleUserPermissionsDelete(
        string $jobId,
        AbstractLegacySource $source,
        string $legacyConnection,
        string $contractId
    ): array {
        $entity = $source->entity();

        $progress = $this->jobService->getEntityProgress($jobId, $entity);

        $totals = [
            'inserted' => 0,
            'failed' => 0,
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'last_id' => null,
            'status' => 'processing',
            'started_at' => $progress['started_at'] ?? (string) now(),
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, [
            'status' => 'processing',
            'started_at' => $totals['started_at'],
        ]);

        try {
            $legacyDbName = (string) Db::connection($legacyConnection)->selectOne('SELECT current_database() AS db')->db;
            $resolvedContractId = $this->idMappingService->resolve('contracts', $legacyDbName, $contractId);

            if ($resolvedContractId === null) {
                throw new \RuntimeException("Contract '{$legacyDbName}' not found in id_mappings; run contracts migration first.");
            }

            $chunkSize = $source->chunkSize();
            $lastId = null;

            while (true) {
                $rows = $source->paginate($legacyConnection, $lastId, $chunkSize);

                if (empty($rows)) {
                    break;
                }

                $legacyUserIds = array_column($rows, 'legacy_user_id');
                $legacyUserIds = array_map('strval', array_filter($legacyUserIds));

                $userIds = $this->idMappingService->resolveMany('users', $legacyUserIds, $contractId);
                $resolvedUserIds = array_values($userIds);

                if (! empty($resolvedUserIds)) {
                    $deleted = Db::connection('conciliador_web')
                        ->table('permission_users')
                        ->where('contract_id', $resolvedContractId)
                        ->whereIn('user_id', $resolvedUserIds)
                        ->delete();

                    $totals['skipped'] += $deleted;
                }

                $lastRow = end($rows);
                $lastId = isset($lastRow[$source->paginationKey()])
                    ? (string) $lastRow[$source->paginationKey()]
                    : null;

                if (count($rows) < $chunkSize) {
                    break;
                }
            }

            $totals['status'] = 'completed';
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), '42P01') || str_contains($e->getMessage(), 'does not exist')) {
                $totals['status'] = 'completed';
            } else {
                $totals['status'] = 'failed';
                $totals['error_message'] = $e->getMessage();
            }
        }

        $totals['finished_at'] = (string) now();
        $this->jobService->updateEntityProgress($jobId, $entity, $totals);
        $this->jobService->incrementTotals($jobId, 0, $totals['failed'], 0);

        return $totals;
    }
}
