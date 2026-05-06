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

use App\Model\MigrationJob;
use Ramsey\Uuid\Uuid;

use function Hyperf\Support\now;

class MigrationJobService
{
    public function create(string $legacyDb, ?string $migrationScope = null): MigrationJob
    {
        $migrationScope ??= $legacyDb;

        return MigrationJob::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'legacy_db' => $legacyDb,
            // Column name kept for schema compatibility. In pull-mode this is
            // the legacy database namespace, not the destination contracts.id.
            'contract_id' => $migrationScope,
            'status' => 'queued',
            'entity_progress' => [],
            'totals' => ['inserted' => 0, 'failed' => 0, 'skipped' => 0],
        ]);
    }

    public function markProcessing(string $jobId): void
    {
        MigrationJob::query()
            ->where('id', $jobId)
            ->update(['status' => 'processing', 'started_at' => now()]);
    }

    public function setCurrentEntity(string $jobId, string $entity): void
    {
        MigrationJob::query()
            ->where('id', $jobId)
            ->update(['current_entity' => $entity]);
    }

    /**
     * Atualiza o progresso de uma entidade dentro do job.
     * O entity_progress é um JSONB:
     *   { "<entity>": { status, last_id, inserted, failed, skipped, started_at, finished_at } }.
     */
    public function updateEntityProgress(string $jobId, string $entity, array $progress): void
    {
        $job = MigrationJob::query()->find($jobId);

        if (! $job) {
            return;
        }

        $current = $job->entity_progress ?? [];
        $current[$entity] = array_merge($current[$entity] ?? [], $progress);

        $job->entity_progress = $current;
        $job->save();
    }

    public function getEntityProgress(string $jobId, string $entity): array
    {
        $job = MigrationJob::query()->find($jobId);

        if (! $job) {
            return [];
        }

        return ($job->entity_progress ?? [])[$entity] ?? [];
    }

    public function incrementTotals(string $jobId, int $inserted, int $failed, int $skipped): void
    {
        $job = MigrationJob::query()->find($jobId);

        if (! $job) {
            return;
        }

        $totals = $job->totals ?? ['inserted' => 0, 'failed' => 0, 'skipped' => 0];
        $totals['inserted'] = ($totals['inserted'] ?? 0) + $inserted;
        $totals['failed'] = ($totals['failed'] ?? 0) + $failed;
        $totals['skipped'] = ($totals['skipped'] ?? 0) + $skipped;

        $job->totals = $totals;
        $job->save();
    }

    public function markCompleted(string $jobId, ?array $errorSummary = null): void
    {
        $job = MigrationJob::query()->find($jobId);

        if (! $job) {
            return;
        }

        $totals = $this->summarizeEntityProgress($job->entity_progress ?? []);
        $hasErrors = ! empty($errorSummary) || ($totals['failed'] ?? 0) > 0;

        $job->status = $hasErrors ? 'completed_with_errors' : 'completed';
        $job->finished_at = now();
        $job->current_entity = null;
        $job->totals = $totals;

        if ($errorSummary !== null) {
            $job->error_summary = $errorSummary;
        }

        $job->save();
    }

    private function summarizeEntityProgress(array $entityProgress): array
    {
        $totals = ['inserted' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($entityProgress as $progress) {
            if (! is_array($progress)) {
                continue;
            }

            $totals['inserted'] += (int) ($progress['inserted'] ?? 0);
            $totals['failed'] += (int) ($progress['failed'] ?? 0);
            $totals['skipped'] += (int) ($progress['skipped'] ?? 0);
        }

        return $totals;
    }

    public function markFailed(string $jobId, string $error): void
    {
        MigrationJob::query()
            ->where('id', $jobId)
            ->update([
                'status' => 'failed',
                'error_summary' => json_encode(['message' => $error]),
                'finished_at' => now(),
            ]);
    }

    public function getStatus(string $jobId): ?array
    {
        $job = MigrationJob::query()->find($jobId);

        if (! $job) {
            return null;
        }

        return [
            'job_id' => $job->id,
            'legacy_db' => $job->legacy_db,
            'contract_id' => $job->contract_id,
            'migration_scope' => $job->contract_id,
            'status' => $job->status,
            'current_entity' => $job->current_entity,
            'totals' => $job->totals ?? [],
            'entity_progress' => $this->sortEntityProgress($job->entity_progress ?? []),
            'error_summary' => $job->error_summary,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'created_at' => $job->created_at,
        ];
    }

    public function listRecent(int $limit = 50): array
    {
        return MigrationJob::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (MigrationJob $job): array {
                $row = $job->toArray();
                $row['migration_scope'] = $row['contract_id'] ?? null;
                $row['entity_progress'] = $this->sortEntityProgress($row['entity_progress'] ?? []);
                return $row;
            })
            ->toArray();
    }

    public function listByMigrationScope(string $migrationScope, int $limit = 50): array
    {
        return MigrationJob::query()
            ->where('contract_id', $migrationScope)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (MigrationJob $job): array {
                $row = $job->toArray();
                $row['migration_scope'] = $row['contract_id'] ?? null;
                $row['entity_progress'] = $this->sortEntityProgress($row['entity_progress'] ?? []);
                return $row;
            })
            ->toArray();
    }

    /**
     * Reordena entity_progress seguindo a ordem canônica de EntityMetadataRegistry::sources().
     * Entidades não reconhecidas (legado de jobs antigos) ficam ao final.
     *
     * @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    private function sortEntityProgress(array $progress): array
    {
        if (empty($progress)) {
            return $progress;
        }

        $order = array_flip(EntityMetadataRegistry::entityNames());
        $sorted = [];

        foreach (EntityMetadataRegistry::entityNames() as $entity) {
            if (isset($progress[$entity])) {
                $sorted[$entity] = $progress[$entity];
            }
        }

        foreach ($progress as $entity => $data) {
            if (! isset($order[$entity])) {
                $sorted[$entity] = $data;
            }
        }

        return $sorted;
    }

    public function listByContract(string $contractId, int $limit = 50): array
    {
        return $this->listByMigrationScope($contractId, $limit);
    }
}
