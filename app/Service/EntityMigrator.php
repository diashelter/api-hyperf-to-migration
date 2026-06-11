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
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function Hyperf\Support\env;
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

        $target = array_key_exists('target', $progress)
            ? $progress['target']
            : $this->withTransientDatabaseRetry(
                static fn (): ?int => $source->count($legacyConnection)
            );

        $totals = [
            'inserted' => (int) ($progress['inserted'] ?? 0),
            'failed' => (int) ($progress['failed'] ?? 0),
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'last_id' => $lastId,
            'target' => $target,
            'status' => 'processing',
            'started_at' => $progress['started_at'] ?? (string) now(),
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, [
            'status' => 'processing',
            'started_at' => $totals['started_at'],
            'last_id' => $lastId,
            'target' => $target,
        ]);

        $hasContractId = $source->hasContractId();
        $useCopy = $source->useCopy();
        $resolveFn = function (array $record, string $cid) use ($fkMap, $hasContractId): array {
            if ($hasContractId) {
                $record = $this->recordPrepResolveContractIdFK($this->idMappingService, $record, $cid);
            }
            return $this->recordPrepResolveForeignKeysFromMap($this->idMappingService, $fkMap, $record, $cid);
        };

        try {
            // Pipeline com overlap producer/consumer: enquanto o consumer faz
            // dedup→prewarm→prepare→insertBatch→storeBatch, o producer já está
            // lendo e transformando a próxima página do legado. Channel pequeno
            // (capacidade 2) gera backpressure natural — o producer aguarda
            // quando o consumer está mais lento.
            $channel = new Channel(2);
            $producerError = null;
            $consumerError = null;
            $cancelled = false;
            $lastConsumerProgressAt = microtime(true);
            $consumerCid = null;

            $parallel = new Parallel(2);

            $parallel->add(function () use (
                $entity,
                $source,
                $legacyConnection,
                $contractId,
                $chunkSize,
                $paginationKey,
                $lastId,
                $channel,
                &$cancelled,
                &$consumerCid,
                &$lastConsumerProgressAt,
                &$producerError
            ) {
                $cursor = $lastId;
                try {
                    while (true) {
                        if ($cancelled) {
                            break;
                        }

                        $rows = $this->withTransientDatabaseRetry(
                            static fn (): array => $source->paginate($legacyConnection, $cursor, $chunkSize)
                        );

                        if ($cancelled) {
                            break;
                        }

                        if (empty($rows)) {
                            break;
                        }
                        $batch = array_map(
                            static fn (array $row): array => $source->transformRow($row, $contractId),
                            $rows
                        );
                        $lastRow = end($rows);
                        $newCursor = isset($lastRow[$paginationKey]) ? (string) $lastRow[$paginationKey] : null;
                        $rowCount = count($rows);

                        $this->pushToChannel($channel, [
                            'transformed_batch' => $batch,
                            'last_id' => $newCursor,
                        ], $entity, $lastConsumerProgressAt, $cancelled, $consumerCid);

                        $cursor = $newCursor;
                        if ($rowCount < $chunkSize) {
                            break;
                        }
                    }
                } catch (Throwable $e) {
                    $producerError = $e;
                } finally {
                    if (! $cancelled) {
                        try {
                            $this->pushToChannel(
                                $channel,
                                null,
                                $entity,
                                $lastConsumerProgressAt,
                                $cancelled,
                                $consumerCid
                            );
                        } catch (Throwable $e) {
                            $producerError ??= $e;
                        }
                    }
                }
            });

            $parallel->add(function () use (
                $source,
                $entity,
                $targetTable,
                $targetConnection,
                $fkMap,
                $normalizeStrings,
                $idStrategy,
                $resolveFn,
                $contractId,
                $jobId,
                $channel,
                $useCopy,
                &$cancelled,
                &$consumerCid,
                &$lastConsumerProgressAt,
                &$totals,
                &$consumerError
            ) {
                try {
                    $consumerCid = Coroutine::getCid();

                    while (true) {
                        $msg = $channel->pop();
                        if ($msg === false || $msg === null) {
                            break;
                        }

                        $transformedBatch = $msg['transformed_batch'];
                        $batch = $transformedBatch;
                        $newLastId = $msg['last_id'];

                        [$batch, $skipped] = $this->recordPrepFilterDuplicates(
                            $this->idMappingService,
                            $entity,
                            $batch,
                            $contractId
                        );

                        [$batch, $skipped] = $this->restoreRecordsWithMissingTargets(
                            $targetConnection,
                            $targetTable,
                            $transformedBatch,
                            $batch,
                            $skipped
                        );

                        [$batch, $reusedMappings] = $this->reuseExistingUsersByEmail(
                            $entity,
                            $targetConnection,
                            $targetTable,
                            $batch
                        );

                        if (! empty($reusedMappings)) {
                            $this->idMappingService->storeBatch($entity, $reusedMappings, $contractId, $jobId);
                            $totals['skipped'] += count($reusedMappings);
                        }

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
                            $result = $useCopy
                                ? $this->insertService->copyBatch(
                                    $targetTable,
                                    $prepared,
                                    0,
                                    $targetConnection
                                )
                                : $this->insertService->insertBatch(
                                    $targetTable,
                                    $prepared,
                                    0,
                                    0,
                                    $targetConnection
                                );

                            $totals['inserted'] += (int) $result['inserted'];
                            $totals['failed'] += (int) $result['failed'];

                            if (! empty($result['errors']) && ! isset($totals['error_message'])) {
                                $totals['error_message'] = $result['errors'][0]['message'] ?? 'unknown insert error';
                            }

                            $insertedMappings = $this->mappingsForSuccessfulInserts($idMappings, $result);
                            if (! empty($insertedMappings)) {
                                $this->idMappingService->storeBatch($entity, $insertedMappings, $contractId, $jobId);
                            }
                        }

                        $totals['skipped'] += count($skipped);
                        $totals['last_id'] = $newLastId;

                        $this->jobService->updateEntityProgress($jobId, $entity, [
                            'last_id' => $newLastId,
                            'inserted' => $totals['inserted'],
                            'failed' => $totals['failed'],
                            'skipped' => $totals['skipped'],
                        ]);
                        $lastConsumerProgressAt = microtime(true);
                    }
                } catch (Throwable $e) {
                    $consumerError = $e;
                    $cancelled = true;
                    $channel->close();
                }
            });

            $parallel->wait();

            if ($producerError !== null) {
                throw $producerError;
            }
            if ($consumerError !== null) {
                throw $consumerError;
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

    /**
     * @param array<string, string> $idMappings legacy_id => new_id
     * @param array<string, mixed> $insertResult
     * @return array<string, string>
     */
    private function mappingsForSuccessfulInserts(array $idMappings, array $insertResult): array
    {
        if (empty($idMappings) || (int) ($insertResult['inserted'] ?? 0) <= 0) {
            return [];
        }

        if ((int) ($insertResult['failed'] ?? 0) === 0) {
            return $idMappings;
        }

        $successfulIds = array_filter(array_map(
            static fn (mixed $id): string => (string) $id,
            (array) ($insertResult['successful_record_ids'] ?? [])
        ));

        if (empty($successfulIds)) {
            return [];
        }

        $successful = array_fill_keys($successfulIds, true);

        return array_filter(
            $idMappings,
            static fn (string $newId): bool => isset($successful[$newId])
        );
    }

    /**
     * Mantem backpressure do Channel sem deixar um consumer travado prender a
     * entidade ate o timeout global do job.
     */
    private function pushToChannel(
        Channel $channel,
        mixed $message,
        string $entity,
        float &$lastConsumerProgressAt,
        bool &$cancelled,
        ?int &$consumerCid
    ): void {
        $stallTimeout = max(0, (int) env('MIGRATION_ENTITY_STALL_TIMEOUT', 900));

        if ($stallTimeout === 0) {
            if ($channel->push($message) === false && ! $cancelled) {
                throw new RuntimeException("Failed to send data for entity '{$entity}' to the migration channel.");
            }

            return;
        }

        $waitSeconds = min(5.0, (float) $stallTimeout);

        while (! $cancelled) {
            if ($channel->push($message, $waitSeconds)) {
                return;
            }

            if ($this->entityPipelineHasStalled($lastConsumerProgressAt, $stallTimeout)) {
                $cancelled = true;
                $channel->close();

                if ($consumerCid !== null && $consumerCid > 0) {
                    Coroutine::cancel($consumerCid);
                }

                throw new RuntimeException(sprintf(
                    "Entity '%s' made no migration progress for %d seconds while waiting for the migration channel.",
                    $entity,
                    $stallTimeout
                ));
            }
        }
    }

    private function entityPipelineHasStalled(float $lastConsumerProgressAt, int $stallTimeout, ?float $now = null): bool
    {
        if ($stallTimeout <= 0) {
            return false;
        }

        return ($now ?? microtime(true)) - $lastConsumerProgressAt >= $stallTimeout;
    }

    /**
     * Reexecuta leituras do banco legado quando a falha é claramente transitória
     * (queda de conexão, limite temporário de recursos ou restart do servidor).
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withTransientDatabaseRetry(callable $operation): mixed
    {
        $maxAttempts = max(1, (int) env('MIGRATION_TRANSIENT_DB_RETRY_ATTEMPTS', 3));
        $delayMs = max(0, (int) env('MIGRATION_TRANSIENT_DB_RETRY_DELAY_MS', 250));
        $attempt = 0;

        while (true) {
            ++$attempt;

            try {
                return $operation();
            } catch (Throwable $e) {
                if ($attempt >= $maxAttempts || ! $this->isTransientDatabaseError($e)) {
                    throw $e;
                }

                $this->sleepBeforeTransientRetry($delayMs * (2 ** ($attempt - 1)));
            }
        }
    }

    private function isTransientDatabaseError(Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach ([
            'SQLSTATE[08000]',
            'SQLSTATE[08001]',
            'SQLSTATE[08006]',
            'SQLSTATE[53300]',
            'SQLSTATE[57P01]',
            'Resource temporarily unavailable',
            'could not send SSL negotiation packet',
            'server closed the connection unexpectedly',
            'terminating connection due to administrator command',
            'could not connect to server',
            'Connection refused',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sleepBeforeTransientRetry(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        if (Coroutine::getCid() >= 0) {
            Coroutine::sleep($delayMs / 1000);
            return;
        }

        usleep($delayMs * 1000);
    }

    /**
     * Mappings locais podem sobreviver a limpezas manuais no banco destino.
     * Quando isso acontece, tratar o registro como duplicado deixa FKs órfãs.
     * Se o new_id mapeado não existe mais no target, reprocessamos o registro e
     * deixamos storeBatch() atualizar o mapping para o novo UUID inserido.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function restoreRecordsWithMissingTargets(
        string $targetConnection,
        string $targetTable,
        array $originalBatch,
        array $toInsert,
        array $skipped
    ): array {
        if (empty($skipped)) {
            return [$toInsert, $skipped];
        }

        $mappedIds = array_values(array_filter(array_map(
            static fn (array $record): ?string => isset($record['new_id']) ? (string) $record['new_id'] : null,
            $skipped
        )));

        if (empty($mappedIds)) {
            return [$toInsert, $skipped];
        }

        try {
            $existingIds = Db::connection($targetConnection)
                ->table($targetTable)
                ->whereIn('id', $mappedIds)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
        } catch (Throwable) {
            return [$toInsert, $skipped];
        }

        $existing = array_fill_keys($existingIds, true);
        $recordsByLegacyId = [];

        foreach ($originalBatch as $record) {
            if (isset($record['legacy_id'])) {
                $recordsByLegacyId[(string) $record['legacy_id']] = $record;
            }
        }

        $remainingSkipped = [];
        foreach ($skipped as $record) {
            $newId = isset($record['new_id']) ? (string) $record['new_id'] : '';
            $legacyId = isset($record['legacy_id']) ? (string) $record['legacy_id'] : '';

            if ($newId !== '' && isset($existing[$newId])) {
                $remainingSkipped[] = $record;
                continue;
            }

            if ($legacyId !== '' && isset($recordsByLegacyId[$legacyId])) {
                $toInsert[] = $recordsByLegacyId[$legacyId];
                continue;
            }

            $remainingSkipped[] = $record;
        }

        return [$toInsert, $remainingSkipped];
    }

    /**
     * O destino compartilha usuários entre contratos. Quando o migrator local é
     * recriado, os mappings somem, mas os users já existentes continuam no
     * conciliador_web. Para não bater na unique de email, reaproveitamos o user
     * existente e recriamos o mapping do legacy_id para o id atual.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private function reuseExistingUsersByEmail(
        string $entity,
        string $targetConnection,
        string $targetTable,
        array $batch
    ): array {
        if ($entity !== 'users' || $targetTable !== 'users' || empty($batch)) {
            return [$batch, []];
        }

        $emailsByIndex = [];
        foreach ($batch as $index => $record) {
            $legacyId = isset($record['legacy_id']) ? (string) $record['legacy_id'] : '';
            $email = strtolower(trim((string) ($record['email'] ?? '')));

            if ($legacyId === '' || $email === '') {
                continue;
            }

            $emailsByIndex[$index] = $email;
        }

        if (empty($emailsByIndex)) {
            return [$batch, []];
        }

        $existingRows = Db::connection($targetConnection)
            ->table($targetTable)
            ->select(['id', 'email'])
            ->whereIn(Db::raw('LOWER(email)'), array_values(array_unique($emailsByIndex)))
            ->get();

        $existingByEmail = [];
        foreach ($existingRows as $row) {
            $row = (array) $row;
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            if ($email !== '' && ! empty($row['id'])) {
                $existingByEmail[$email] = (string) $row['id'];
            }
        }

        if (empty($existingByEmail)) {
            return [$batch, []];
        }

        $remaining = [];
        $reusedMappings = [];

        foreach ($batch as $index => $record) {
            $legacyId = isset($record['legacy_id']) ? (string) $record['legacy_id'] : '';
            $email = $emailsByIndex[$index] ?? null;

            if ($legacyId !== '' && $email !== null && isset($existingByEmail[$email])) {
                $reusedMappings[$legacyId] = $existingByEmail[$email];
                continue;
            }

            $remaining[] = $record;
        }

        return [$remaining, $reusedMappings];
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

        if ($handler === 'confrontation_conciliations_pivot') {
            return $this->handleConfrontationConciliationsPivot($jobId, $source, $legacyConnection, $contractId);
        }

        if ($handler === 'user_company_restrictions_pivot') {
            return $this->handleUserCompanyRestrictionsPivot($jobId, $source, $legacyConnection, $contractId);
        }

        if ($handler === 'user_permissions_delete') {
            return $this->handleUserPermissionsDelete($jobId, $source, $legacyConnection, $contractId);
        }

        $entity = $source->entity();
        try {
            $target = $source->count($legacyConnection);
        } catch (Throwable) {
            $target = null;
        }
        $totals = [
            'status' => 'pending_special_handler',
            'special_handler' => $handler,
            'inserted' => 0,
            'failed' => 0,
            'skipped' => 0,
            'target' => $target,
            'finished_at' => (string) now(),
            'message' => "Entity '{$entity}' requires special handler '{$handler}'; not implemented in pull-mode yet.",
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, $totals);

        return $totals;
    }

    /**
     * Relacao `confrontation_conciliations` sem PK, sem timestamps e sem mapping proprio.
     * Usa a chave natural unica (bank, financial) para idempotencia.
     */
    private function handleConfrontationConciliationsPivot(
        string $jobId,
        AbstractLegacySource $source,
        string $legacyConnection,
        string $contractId
    ): array {
        $entity = $source->entity();

        $progress = $this->jobService->getEntityProgress($jobId, $entity);
        $target = array_key_exists('target', $progress)
            ? $progress['target']
            : $source->count($legacyConnection);

        $totals = [
            'inserted' => (int) ($progress['inserted'] ?? 0),
            'failed' => (int) ($progress['failed'] ?? 0),
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'last_id' => $progress['last_id'] ?? null,
            'target' => $target,
            'status' => 'processing',
            'started_at' => $progress['started_at'] ?? (string) now(),
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, [
            'status' => 'processing',
            'started_at' => $totals['started_at'],
            'last_id' => $totals['last_id'],
            'target' => $target,
        ]);

        try {
            $rows = $source->paginate($legacyConnection, null, $source->chunkSize());

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
                    $confrontationId = ! empty($row['legacy_confrontation_id'])
                        ? $this->idMappingService->resolve('confrontations', (string) $row['legacy_confrontation_id'], $contractId)
                        : null;

                    $bankRecordId = ! empty($row['legacy_confrontation_records_bank'])
                        ? $this->idMappingService->resolve('confrontation_records', (string) $row['legacy_confrontation_records_bank'], $contractId)
                        : null;

                    $financialRecordId = ! empty($row['legacy_confrontation_records_financial'])
                        ? $this->idMappingService->resolve('confrontation_records', (string) $row['legacy_confrontation_records_financial'], $contractId)
                        : null;

                    if ($confrontationId === null || $bankRecordId === null || $financialRecordId === null) {
                        $failed++;
                        continue;
                    }

                    $records[] = [
                        'confrontation_id' => $confrontationId,
                        'confrontation_records_bank' => $bankRecordId,
                        'confrontation_records_financial' => $financialRecordId,
                    ];
                }

                $inserted = 0;
                $skipped = 0;

                if (! empty($records)) {
                    [$records, $alreadyExisting] = $this->filterExistingConfrontationConciliationRecords($records);
                    $skipped += $alreadyExisting;
                }

                if (! empty($records)) {
                    $connection = Db::connection('conciliador_web');
                    $connection->beginTransaction();
                    try {
                        $inserted = $connection->table($source->targetTable())->insertOrIgnore($records);
                        $connection->commit();
                        $skipped += count($records) - $inserted;
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
            if ($totals['failed'] > 0) {
                $totals['error_message'] = sprintf('%d confrontation conciliation records could not resolve required FKs.', $totals['failed']);
            }
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
     * @param array<int, array<string, mixed>> $records
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function filterExistingConfrontationConciliationRecords(array $records): array
    {
        if (empty($records)) {
            return [$records, 0];
        }

        $uniqueRecords = [];
        $bankIds = [];
        $financialIds = [];
        $skipped = 0;

        foreach ($records as $record) {
            $key = $this->confrontationConciliationRecordKey($record);

            if ($key !== null && isset($uniqueRecords[$key])) {
                $skipped++;
                continue;
            }

            if ($key !== null) {
                $uniqueRecords[$key] = $record;
                $bankIds[(string) $record['confrontation_records_bank']] = true;
                $financialIds[(string) $record['confrontation_records_financial']] = true;
                continue;
            }

            $uniqueRecords[] = $record;
        }

        if (empty($bankIds) || empty($financialIds)) {
            return [array_values($uniqueRecords), $skipped];
        }

        $existing = [];
        foreach (array_chunk(array_keys($bankIds), 1000) as $bankIdChunk) {
            $rows = Db::connection('conciliador_web')
                ->table('confrontation_conciliations')
                ->select(['confrontation_records_bank', 'confrontation_records_financial'])
                ->whereIn('confrontation_records_bank', $bankIdChunk)
                ->whereIn('confrontation_records_financial', array_keys($financialIds))
                ->get();

            foreach ($rows as $row) {
                $row = (array) $row;
                $key = $this->confrontationConciliationRecordKey($row);

                if ($key !== null) {
                    $existing[$key] = true;
                }
            }
        }

        $toInsert = [];
        foreach ($uniqueRecords as $record) {
            $key = $this->confrontationConciliationRecordKey($record);

            if ($key !== null && isset($existing[$key])) {
                $skipped++;
                continue;
            }

            $toInsert[] = $record;
        }

        return [$toInsert, $skipped];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function confrontationConciliationRecordKey(array $record): ?string
    {
        $bankId = isset($record['confrontation_records_bank']) ? (string) $record['confrontation_records_bank'] : '';
        $financialId = isset($record['confrontation_records_financial']) ? (string) $record['confrontation_records_financial'] : '';

        if ($bankId === '' || $financialId === '') {
            return null;
        }

        return $bankId . ':' . $financialId;
    }

    /**
     * Pivot `contract_user` (N:N, sem PK, sem timestamps).
     *
     * Fluxo:
     *   1. Lê todos os vínculos do legado via ContractUserSource (singleton load).
     *   2. Resolve user_id e contract_id via IdMappingService.
     *   3. Resolve role_id pelo label ('owner'/'user') via LookupCacheService.
     *   4. Filtra existentes por (contract_id, user_id).
     *   5. insertOrIgnore() — sem storeBatch (pivot sem id próprio).
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
        $target = array_key_exists('target', $progress)
            ? $progress['target']
            : $source->count($legacyConnection);

        $totals = [
            'inserted' => (int) ($progress['inserted'] ?? 0),
            'failed' => (int) ($progress['failed'] ?? 0),
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'last_id' => $lastId,
            'target' => $target,
            'status' => 'processing',
            'started_at' => $progress['started_at'] ?? (string) now(),
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, [
            'status' => 'processing',
            'started_at' => $totals['started_at'],
            'last_id' => $lastId,
            'target' => $target,
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
                    [$records, $alreadyExisting] = $this->filterExistingContractUserRecords($records);
                    $skipped += $alreadyExisting;
                }

                if (! empty($records)) {
                    $connection = Db::connection('conciliador_web');
                    $connection->beginTransaction();
                    try {
                        $inserted = $connection->table('contract_user')->insertOrIgnore($records);
                        $connection->commit();
                        $skipped += count($records) - $inserted;
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
     * `insertOrIgnore()` só evita duplicidade quando o banco destino possui uma
     * constraint única compatível. Como `contract_user` pode não ter essa
     * constraint no ambiente de destino, filtramos pelo par de negócio antes.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function filterExistingContractUserRecords(array $records): array
    {
        if (empty($records)) {
            return [$records, 0];
        }

        $uniqueRecords = [];
        $userIds = [];
        $contractIds = [];
        $skipped = 0;

        foreach ($records as $record) {
            $key = $this->contractUserRecordKey($record);

            if ($key !== null && isset($uniqueRecords[$key])) {
                $skipped++;
                continue;
            }

            if ($key !== null) {
                $uniqueRecords[$key] = $record;
                $userIds[(string) $record['user_id']] = true;
                $contractIds[(string) $record['contract_id']] = true;
                continue;
            }

            $uniqueRecords[] = $record;
        }

        if (empty($userIds) || empty($contractIds)) {
            return [array_values($uniqueRecords), $skipped];
        }

        $existing = [];
        foreach (array_chunk(array_keys($userIds), 1000) as $userIdChunk) {
            $rows = Db::connection('conciliador_web')
                ->table('contract_user')
                ->select(['user_id', 'contract_id'])
                ->whereIn('user_id', $userIdChunk)
                ->whereIn('contract_id', array_keys($contractIds))
                ->get();

            foreach ($rows as $row) {
                $row = (array) $row;
                $key = $this->contractUserRecordKey($row);

                if ($key !== null) {
                    $existing[$key] = true;
                }
            }
        }

        $toInsert = [];
        foreach ($uniqueRecords as $record) {
            $key = $this->contractUserRecordKey($record);

            if ($key !== null && isset($existing[$key])) {
                $skipped++;
                continue;
            }

            $toInsert[] = $record;
        }

        return [$toInsert, $skipped];
    }

    /**
     * Chave natural do pivot. `role_id` e `contract_admin` são atributos do
     * vínculo; não devem permitir um segundo vínculo para o mesmo usuário.
     *
     * @param array<string, mixed> $record
     */
    private function contractUserRecordKey(array $record): ?string
    {
        $userId = isset($record['user_id']) ? (string) $record['user_id'] : '';
        $contractId = isset($record['contract_id']) ? (string) $record['contract_id'] : '';

        if ($userId === '' || $contractId === '') {
            return null;
        }

        return $contractId . ':' . $userId;
    }

    /**
     * Relacao `user_company_restrictions` por chave composta, sem id, sem timestamps
     * e sem mappings proprios. Usa (contract_id, user_id, company_id) para idempotencia.
     */
    private function handleUserCompanyRestrictionsPivot(
        string $jobId,
        AbstractLegacySource $source,
        string $legacyConnection,
        string $contractId
    ): array {
        $entity = $source->entity();

        $progress = $this->jobService->getEntityProgress($jobId, $entity);
        $lastId = $progress['last_id'] ?? null;
        $target = array_key_exists('target', $progress)
            ? $progress['target']
            : $source->count($legacyConnection);

        $totals = [
            'inserted' => (int) ($progress['inserted'] ?? 0),
            'failed' => (int) ($progress['failed'] ?? 0),
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'last_id' => $lastId,
            'target' => $target,
            'status' => 'processing',
            'started_at' => $progress['started_at'] ?? (string) now(),
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, [
            'status' => 'processing',
            'started_at' => $totals['started_at'],
            'last_id' => $lastId,
            'target' => $target,
        ]);

        try {
            $resolvedContractId = $this->idMappingService->resolve('contracts', $contractId, $contractId);

            if ($resolvedContractId === null) {
                throw new \RuntimeException("Contract '{$contractId}' not found in id_mappings; run contracts migration first.");
            }

            $chunkSize = $source->chunkSize();

            while (true) {
                $rows = $source->paginate($legacyConnection, $lastId, $chunkSize);

                if (empty($rows)) {
                    break;
                }

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

                    $companyId = ! empty($row['legacy_company_id'])
                        ? $this->idMappingService->resolve('companies', (string) $row['legacy_company_id'], $contractId)
                        : null;

                    if ($userId === null || $companyId === null) {
                        $failed++;
                        continue;
                    }

                    $records[] = [
                        'contract_id' => $resolvedContractId,
                        'user_id' => $userId,
                        'company_id' => $companyId,
                    ];
                }

                $inserted = 0;
                $skipped = 0;

                if (! empty($records)) {
                    [$records, $alreadyExisting] = $this->filterExistingUserCompanyRestrictionRecords($records);
                    $skipped += $alreadyExisting;
                }

                if (! empty($records)) {
                    $connection = Db::connection('conciliador_web');
                    $connection->beginTransaction();
                    try {
                        $inserted = $connection->table('user_company_restrictions')->insertOrIgnore($records);
                        $connection->commit();
                        $skipped += count($records) - $inserted;
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
            if ($totals['failed'] > 0) {
                $totals['error_message'] = sprintf('%d user company restriction records could not resolve required FKs.', $totals['failed']);
            }
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
     * @param array<int, array<string, mixed>> $records
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function filterExistingUserCompanyRestrictionRecords(array $records): array
    {
        if (empty($records)) {
            return [$records, 0];
        }

        $uniqueRecords = [];
        $contractIds = [];
        $userIds = [];
        $companyIds = [];
        $skipped = 0;

        foreach ($records as $record) {
            $key = $this->userCompanyRestrictionRecordKey($record);

            if ($key !== null && isset($uniqueRecords[$key])) {
                $skipped++;
                continue;
            }

            if ($key !== null) {
                $uniqueRecords[$key] = $record;
                $contractIds[(string) $record['contract_id']] = true;
                $userIds[(string) $record['user_id']] = true;
                $companyIds[(string) $record['company_id']] = true;
                continue;
            }

            $uniqueRecords[] = $record;
        }

        if (empty($contractIds) || empty($userIds) || empty($companyIds)) {
            return [array_values($uniqueRecords), $skipped];
        }

        $existing = [];
        foreach (array_chunk(array_keys($userIds), 1000) as $userIdChunk) {
            $rows = Db::connection('conciliador_web')
                ->table('user_company_restrictions')
                ->select(['contract_id', 'user_id', 'company_id'])
                ->whereIn('contract_id', array_keys($contractIds))
                ->whereIn('user_id', $userIdChunk)
                ->whereIn('company_id', array_keys($companyIds))
                ->get();

            foreach ($rows as $row) {
                $row = (array) $row;
                $key = $this->userCompanyRestrictionRecordKey($row);

                if ($key !== null) {
                    $existing[$key] = true;
                }
            }
        }

        $toInsert = [];
        foreach ($uniqueRecords as $record) {
            $key = $this->userCompanyRestrictionRecordKey($record);

            if ($key !== null && isset($existing[$key])) {
                $skipped++;
                continue;
            }

            $toInsert[] = $record;
        }

        return [$toInsert, $skipped];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function userCompanyRestrictionRecordKey(array $record): ?string
    {
        $contractId = isset($record['contract_id']) ? (string) $record['contract_id'] : '';
        $userId = isset($record['user_id']) ? (string) $record['user_id'] : '';
        $companyId = isset($record['company_id']) ? (string) $record['company_id'] : '';

        if ($contractId === '' || $userId === '' || $companyId === '') {
            return null;
        }

        return $contractId . ':' . $userId . ':' . $companyId;
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
        $target = $progress['target'] ?? null;
        if (! array_key_exists('target', $progress)) {
            try {
                $target = $source->count($legacyConnection);
            } catch (Throwable) {
                $target = null;
            }
        }

        $totals = [
            'inserted' => 0,
            'failed' => 0,
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'last_id' => null,
            'target' => $target,
            'status' => 'processing',
            'started_at' => $progress['started_at'] ?? (string) now(),
        ];

        $this->jobService->updateEntityProgress($jobId, $entity, [
            'status' => 'processing',
            'started_at' => $totals['started_at'],
            'target' => $target,
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
