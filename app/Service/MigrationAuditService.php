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

use App\Model\MigrationAuditLog;
use Hyperf\Di\Annotation\Inject;
use Ramsey\Uuid\Uuid;
use Throwable;

use function Hyperf\Support\env;

class MigrationAuditService
{
    #[Inject]
    protected ParallelInsertService $insertService;

    /** @var array<string, float> */
    private array $startedAtMicrotime = [];

    public function open(
        string $requestId,
        string $contractId,
        string $entity,
        array $rawBatch,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $this->startedAtMicrotime[$requestId] = microtime(true);

        try {
            MigrationAuditLog::query()->create([
                'id' => Uuid::uuid7()->toString(),
                'request_id' => $requestId,
                'contract_id' => $contractId,
                'entity' => $entity,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'total_received' => count($rawBatch),
                'status' => 'received',
                'request_payload' => json_encode($rawBatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            error_log(sprintf('[migration-audit] open failed: %s', $exception->getMessage()));
        }
    }

    public function close(string $requestId, array $result, ?string $batchId = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $errors = $result['errors'] ?? [];
        $validationErrors = array_values(array_filter(
            $errors,
            static fn (mixed $error): bool => is_array($error) && array_key_exists('validation_errors', $error)
        ));
        $insertErrors = array_values(array_filter(
            $errors,
            static fn (mixed $error): bool => is_array($error) && ! array_key_exists('validation_errors', $error)
        ));

        $totalInserted = (int) ($result['inserted'] ?? $result['deleted'] ?? 0);
        $totalFailed = (int) ($result['failed'] ?? 0);
        $totalSkipped = (int) ($result['skipped'] ?? 0);
        $totalInvalid = count($validationErrors);
        $totalValid = max(0, $totalInserted + $totalSkipped + max(0, $totalFailed - $totalInvalid));

        try {
            MigrationAuditLog::query()
                ->where('request_id', $requestId)
                ->update([
                    'batch_id' => $batchId,
                    'total_valid' => $totalValid,
                    'total_invalid' => $totalInvalid,
                    'total_inserted' => $totalInserted,
                    'total_failed' => $totalFailed,
                    'total_skipped' => $totalSkipped,
                    'status' => $this->determineStatus($result),
                    'response_payload' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'validation_errors' => empty($validationErrors)
                        ? null
                        : json_encode($validationErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'insert_errors' => empty($insertErrors)
                        ? null
                        : json_encode($insertErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'completed_at' => date('Y-m-d H:i:s'),
                    'processing_time_ms' => $this->computeProcessingTimeMs($requestId),
                ]);
        } catch (Throwable $exception) {
            error_log(sprintf('[migration-audit] close failed: %s', $exception->getMessage()));
        }
    }

    public function logRecords(string $requestId, string $contractId, string $entity, array $recordLogs): void
    {
        if (! $this->shouldLogRecords($entity) || empty($recordLogs)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $payload = [];

        foreach ($recordLogs as $recordLog) {
            $payload[] = [
                'id' => Uuid::uuid7()->toString(),
                'request_id' => $requestId,
                'contract_id' => $contractId,
                'entity' => $entity,
                'legacy_id' => isset($recordLog['legacy_id']) ? (string) $recordLog['legacy_id'] : null,
                'new_id' => $recordLog['new_id'] ?? null,
                'status' => $recordLog['status'] ?? 'failed',
                'error_message' => $recordLog['error_message'] ?? null,
                'created_at' => $now,
            ];
        }

        try {
            $this->insertService->insertSync('migration_record_logs', $payload, 'default');
        } catch (Throwable $exception) {
            error_log(sprintf('[migration-audit] logRecords failed: %s', $exception->getMessage()));
        }
    }

    public function shouldLogRecords(string $entity): bool
    {
        if (! $this->isEnabled() || ! $this->toBool(env('MIGRATION_LOG_RECORDS', true))) {
            return false;
        }

        $skipEntities = array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) env('MIGRATION_SKIP_LOG_ENTITIES', 'import_records,confrontation_records,rules'))
        ));

        return ! in_array($entity, $skipEntities, true);
    }

    private function isEnabled(): bool
    {
        return $this->toBool(env('MIGRATION_AUDIT_ENABLED', true));
    }

    private function determineStatus(array $result): string
    {
        if (isset($result['error'])) {
            return 'failed';
        }

        return ((int) ($result['failed'] ?? 0)) > 0
            ? 'completed_with_errors'
            : 'completed';
    }

    private function computeProcessingTimeMs(string $requestId): int
    {
        $startedAt = $this->startedAtMicrotime[$requestId] ?? null;

        if ($startedAt === null) {
            return 0;
        }

        unset($this->startedAtMicrotime[$requestId]);

        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
