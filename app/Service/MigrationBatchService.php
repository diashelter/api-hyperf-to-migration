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

use App\Model\MigrationBatch;
use Ramsey\Uuid\Uuid;

use function Hyperf\Support\now;

class MigrationBatchService
{
    public function create(string $entity, int $totalRecords, string $contractId): MigrationBatch
    {
        return MigrationBatch::query()->create([
            'id' => Uuid::uuid7()->toString(),
            'contract_id' => $contractId,
            'entity' => $entity,
            'status' => 'queued',
            'total_records' => $totalRecords,
            'processed_records' => 0,
            'failed_records' => 0,
            'started_at' => now(),
        ]);
    }

    public function markProcessing(string $batchId): void
    {
        MigrationBatch::query()
            ->where('id', $batchId)
            ->update(['status' => 'processing', 'started_at' => now()]);
    }

    public function updateProgress(string $batchId, int $processed, int $failed): void
    {
        MigrationBatch::query()
            ->where('id', $batchId)
            ->update([
                'processed_records' => $processed,
                'failed_records' => $failed,
            ]);
    }

    public function markCompleted(string $batchId, int $processed, int $failed, ?array $errors = null): void
    {
        MigrationBatch::query()
            ->where('id', $batchId)
            ->update([
                'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
                'processed_records' => $processed,
                'failed_records' => $failed,
                'error_details' => $errors ? json_encode($errors) : null,
                'completed_at' => now(),
            ]);
    }

    public function markFailed(string $batchId, string $error): void
    {
        MigrationBatch::query()
            ->where('id', $batchId)
            ->update([
                'status' => 'failed',
                'error_details' => json_encode(['message' => $error]),
                'completed_at' => now(),
            ]);
    }

    public function getStatus(string $batchId): ?array
    {
        $batch = MigrationBatch::query()->find($batchId);

        if (! $batch) {
            return null;
        }

        $progress = $batch->total_records > 0
            ? round(($batch->processed_records / $batch->total_records) * 100, 2)
            : 0;

        return [
            'migration_batch_id' => $batch->id,
            'entity' => $batch->entity,
            'status' => $batch->status,
            'total_records' => $batch->total_records,
            'processed_records' => $batch->processed_records,
            'failed_records' => $batch->failed_records,
            'progress_percentage' => $progress,
            'errors' => $batch->error_details ? json_decode($batch->error_details, true) : [],
            'started_at' => $batch->started_at,
            'completed_at' => $batch->completed_at,
        ];
    }

    public function listByContract(string $contractId): array
    {
        return MigrationBatch::query()
            ->where('contract_id', $contractId)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }
}
