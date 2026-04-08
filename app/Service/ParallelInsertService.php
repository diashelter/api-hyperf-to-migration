<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Ramsey\Uuid\Uuid;

use function Hyperf\Support\env;

class ParallelInsertService
{
    public function insertBatch(
        string $table,
        array $records,
        int $chunkSize = 0,
        int $maxCoroutines = 0,
        string $connection = 'default'
    ): array {
        $chunkSize = $chunkSize ?: (int) env('MIGRATION_CHUNK_SIZE', 1000);
        $maxCoroutines = $maxCoroutines ?: (int) env('MIGRATION_MAX_COROUTINES', 5);

        $records = $this->ensureUuids($this->normalizeRecords($records));
        $chunks = array_chunk($records, $chunkSize);
        $parallel = new Parallel($maxCoroutines);
        $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];

        foreach ($chunks as $index => $chunk) {
            $parallel->add(function () use ($table, $chunk, $index, $connection) {
                try {
                    Db::connection($connection)->table($table)->insert($chunk);
                    return ['success' => true, 'count' => count($chunk), 'index' => $index];
                } catch (\Throwable $e) {
                    return [
                        'success' => false,
                        'count' => 0,
                        'index' => $index,
                        'error' => $e->getMessage(),
                    ];
                }
            });
        }

        $chunkResults = $parallel->wait();

        foreach ($chunkResults as $chunkResult) {
            if ($chunkResult['success']) {
                $results['inserted'] += $chunkResult['count'];
            } else {
                $results['failed'] += count($chunks[$chunkResult['index']] ?? []);
                $results['errors'][] = [
                    'chunk_index' => $chunkResult['index'],
                    'message' => $chunkResult['error'],
                ];
            }
        }

        return $results;
    }

    public function insertSync(string $table, array $records, string $connection = 'default'): array
    {
        $records = $this->ensureUuids($this->normalizeRecords($records));
        $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];

        try {
            Db::connection($connection)->beginTransaction();
            Db::connection($connection)->table($table)->insert($records);
            Db::connection($connection)->commit();
            $results['inserted'] = count($records);
        } catch (\Throwable $e) {
            Db::connection($connection)->rollBack();
            $results['failed'] = count($records);
            $results['errors'][] = ['message' => $e->getMessage()];
        }

        return $results;
    }

    public function upsertBatch(
        string $table,
        array $records,
        array $uniqueKeys,
        array $updateColumns,
        int $chunkSize = 0,
        int $maxCoroutines = 0
    ): array {
        $chunkSize = $chunkSize ?: (int) env('MIGRATION_CHUNK_SIZE', 1000);
        $maxCoroutines = $maxCoroutines ?: (int) env('MIGRATION_MAX_COROUTINES', 5);

        $records = $this->ensureUuids($records);
        $chunks = array_chunk($records, $chunkSize);
        $parallel = new Parallel($maxCoroutines);
        $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];

        foreach ($chunks as $index => $chunk) {
            $parallel->add(function () use ($table, $chunk, $uniqueKeys, $updateColumns, $index) {
                try {
                    Db::table($table)->upsert($chunk, $uniqueKeys, $updateColumns);
                    return ['success' => true, 'count' => count($chunk), 'index' => $index];
                } catch (\Throwable $e) {
                    return [
                        'success' => false,
                        'count' => 0,
                        'index' => $index,
                        'error' => $e->getMessage(),
                    ];
                }
            });
        }

        $chunkResults = $parallel->wait();

        foreach ($chunkResults as $chunkResult) {
            if ($chunkResult['success']) {
                $results['inserted'] += $chunkResult['count'];
            } else {
                $results['failed'] += count($chunks[$chunkResult['index']] ?? []);
                $results['errors'][] = [
                    'chunk_index' => $chunkResult['index'],
                    'message' => $chunkResult['error'],
                ];
            }
        }

        return $results;
    }

    private function ensureUuids(array $records): array
    {
        foreach ($records as &$record) {
            if (empty($record['id'])) {
                $record['id'] = Uuid::uuid4()->toString();
            }
        }

        return $records;
    }

    /**
     * Garante que todos os registros do batch têm exatamente as mesmas chaves.
     * Campos ausentes são preenchidos com null para evitar o erro do PostgreSQL
     * "VALUES lists must all be the same length" em INSERTs com múltiplas linhas.
     */
    private function normalizeRecords(array $records): array
    {
        if (count($records) <= 1) {
            return $records;
        }

        $allKeys = array_unique(array_merge(...array_map('array_keys', $records)));

        return array_map(static function (array $record) use ($allKeys): array {
            foreach ($allKeys as $key) {
                if (! array_key_exists($key, $record)) {
                    $record[$key] = null;
                }
            }
            return $record;
        }, $records);
    }
}
