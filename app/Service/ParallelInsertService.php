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

use Hyperf\Context\ApplicationContext;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

use function Hyperf\Support\env;

class ParallelInsertService
{
    /**
     * @var array<string, bool>
     */
    private array $copyWarnings = [];

    public function insertBatch(
        string $table,
        array $records,
        int $chunkSize = 0,
        int $maxCoroutines = 0,
        string $connection = 'default'
    ): array {
        $chunkSize = $chunkSize ?: max(1, (int) env('MIGRATION_CHUNK_SIZE', 1000));
        $maxCoroutines = $maxCoroutines ?: max(1, (int) env('MIGRATION_MAX_COROUTINES', 5));

        $records = $this->ensureUuids($this->normalizeRecords($records));
        $chunks = array_chunk($records, $chunkSize);
        $parallel = new Parallel($maxCoroutines);
        $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];

        foreach ($chunks as $index => $chunk) {
            $parallel->add(function () use ($table, $chunk, $index, $connection) {
                try {
                    Db::connection($connection)->table($table)->insert($chunk);
                    return ['success' => true, 'count' => count($chunk), 'index' => $index];
                } catch (Throwable $e) {
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

    /**
     * Insert via PostgreSQL COPY FROM STDIN - caminho rapido para tabelas de
     * altíssimo volume (import_records, confrontation_records, rules).
     *
     * O driver PDO (`pgsqlCopyFromArray`/`pgsqlCopyFromFile`) trava neste
     * ambiente em COPY FROM STDIN. Por isso este metodo usa a extensao nativa
     * `pgsql` quando ela existe. Se a imagem ainda nao tiver `php84-pgsql`,
     * cai para bulk insert multi-row com chunks calibrados para o limite de
     * parametros do PostgreSQL e paralelismo por corrotinas Hyperf.
     *
     * Idempotencia: cada chunk de escrita e atomico. Em falha, o chunk
     * afetado retorna em `failed` e o caller nao chama `storeBatch` para ele.
     */
    public function copyBatch(
        string $table,
        array $records,
        int $chunkSize = 0,
        string $connection = 'conciliador_web'
    ): array {
        $chunkSize = $chunkSize ?: max(1, (int) env('MIGRATION_COPY_CHUNK_SIZE', 5000));
        $records = $this->ensureUuids($this->normalizeRecords($records));

        $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];
        if (empty($records)) {
            return $results;
        }

        $driver = $this->copyDriver();

        if ($driver === 'bulk_insert') {
            return $this->bulkInsertBatch($table, $records, $chunkSize, $connection);
        }

        if ($driver !== 'native') {
            $this->warnCopyFallback('invalid_copy_driver', [
                'driver' => $driver,
                'table' => $table,
                'connection' => $connection,
            ]);

            return $this->bulkInsertBatch($table, $records, $chunkSize, $connection);
        }

        if (! function_exists('pg_connect')) {
            $this->warnCopyFallback('missing_pgsql_extension', [
                'table' => $table,
                'connection' => $connection,
            ]);

            return $this->bulkInsertBatch($table, $records, $chunkSize, $connection);
        }

        return $this->nativeCopyBatch($table, $records, $chunkSize, $connection);
    }

    private function copyDriver(): string
    {
        return str_replace('-', '_', strtolower((string) env('MIGRATION_COPY_DRIVER', 'native')));
    }

    private function nativeCopyBatch(string $table, array $records, int $chunkSize, string $connection): array
    {
        $columns = array_keys($records[0]);
        $chunks = array_chunk($records, $chunkSize);
        $maxCoroutines = max(1, (int) env('MIGRATION_COPY_COROUTINES', 2));

        $results = $this->runChunkJobs(
            $chunks,
            $maxCoroutines,
            function (array $chunk) use ($table, $columns, $connection): void {
                $this->copyChunkNative($table, $chunk, $columns, $connection);
            }
        );

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @param array<int, string> $columns
     */
    private function copyChunkNative(string $table, array $chunk, array $columns, string $connection): void
    {
        $pg = null;
        $copyStarted = false;
        $inTransaction = false;
        $sep = "\t";
        $nullStr = '\\N';

        try {
            $pg = $this->openNativePgConnection($connection);

            $this->pgQueryOrFail($pg, 'BEGIN');
            $inTransaction = true;

            $columnList = implode(', ', array_map(fn (string $c): string => $this->quoteIdentifier($c), $columns));
            $copySql = sprintf(
                "COPY %s (%s) FROM STDIN WITH (FORMAT text, DELIMITER E'\\t', NULL '\\N')",
                $this->quoteQualifiedIdentifier($table),
                $columnList
            );

            $this->pgQueryOrFail($pg, $copySql);
            $copyStarted = true;

            foreach ($chunk as $row) {
                $ok = call_user_func('pg_put_line', $pg, $this->formatCopyRow($row, $columns, $sep, $nullStr) . "\n");
                if ($ok === false) {
                    throw new RuntimeException('pg_put_line failed: ' . $this->pgLastError($pg));
                }
            }

            $ok = call_user_func('pg_end_copy', $pg);
            if ($ok === false) {
                throw new RuntimeException('pg_end_copy failed: ' . $this->pgLastError($pg));
            }
            $copyStarted = false;

            $this->pgQueryOrFail($pg, 'COMMIT');
            $inTransaction = false;
        } catch (Throwable $e) {
            if ($pg !== null) {
                if ($copyStarted) {
                    @call_user_func('pg_end_copy', $pg);
                }
                if ($inTransaction) {
                    @call_user_func('pg_query', $pg, 'ROLLBACK');
                }
            }

            throw $e;
        } finally {
            if ($pg !== null) {
                @call_user_func('pg_close', $pg);
            }
        }
    }

    private function openNativePgConnection(string $connection)
    {
        $conn = call_user_func('pg_connect', $this->pgConnectionString($connection));
        if ($conn === false) {
            throw new RuntimeException(sprintf('pg_connect failed for connection [%s]', $connection));
        }

        return $conn;
    }

    private function pgQueryOrFail($pg, string $sql): void
    {
        $result = call_user_func('pg_query', $pg, $sql);
        if ($result === false) {
            throw new RuntimeException('pg_query failed: ' . $this->pgLastError($pg));
        }
    }

    private function pgLastError($pg): string
    {
        $error = call_user_func('pg_last_error', $pg);

        return is_string($error) && $error !== '' ? $error : 'unknown pgsql error';
    }

    private function pgConnectionString(string $connection): string
    {
        $config = $this->databaseConfig($connection);

        return implode(' ', [
            'host=' . $this->quotePgConnectionValue($config['host']),
            'port=' . $this->quotePgConnectionValue($config['port']),
            'dbname=' . $this->quotePgConnectionValue($config['database']),
            'user=' . $this->quotePgConnectionValue($config['username']),
            'password=' . $this->quotePgConnectionValue($config['password']),
        ]);
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function databaseConfig(string $connection): array
    {
        return match ($connection) {
            'conciliador_web' => [
                'host' => (string) env('DB_WEB_HOST', 'postgres'),
                'port' => (string) env('DB_WEB_PORT', 5432),
                'database' => (string) env('DB_WEB_DATABASE', 'conciliador'),
                'username' => (string) env('DB_WEB_USERNAME', 'postgres'),
                'password' => (string) env('DB_WEB_PASSWORD', 'conciliador'),
            ],
            'legacy_database' => [
                'host' => (string) env('DB_LEGACY_HOST', 'postgres'),
                'port' => (string) env('DB_LEGACY_PORT', 5432),
                'database' => (string) env('DB_LEGACY_DATABASE', ''),
                'username' => (string) env('DB_LEGACY_USERNAME', 'postgres'),
                'password' => (string) env('DB_LEGACY_PASSWORD', 'conciliador'),
            ],
            default => [
                'host' => (string) env('DB_HOST', 'postgres'),
                'port' => (string) env('DB_PORT', 5432),
                'database' => (string) env('DB_DATABASE', 'conciliador'),
                'username' => (string) env('DB_USERNAME', 'conciliador'),
                'password' => (string) env('DB_PASSWORD', 'conciliador'),
            ],
        };
    }

    private function quotePgConnectionValue(string $value): string
    {
        if ($value === '') {
            return "''";
        }

        if (! preg_match('/[\s\\\\\']/', $value)) {
            return $value;
        }

        return "'" . strtr($value, [
            '\\' => '\\\\',
            "'" => "\\'",
        ]) . "'";
    }

    private function bulkInsertBatch(string $table, array $records, int $chunkSize, string $connection): array
    {
        $columns = array_keys($records[0]);
        $chunkSize = $this->bulkInsertChunkSize(count($columns), $chunkSize);
        $chunks = array_chunk($records, $chunkSize);
        $maxCoroutines = max(1, (int) env('MIGRATION_COPY_COROUTINES', 2));

        $results = $this->runChunkJobs(
            $chunks,
            $maxCoroutines,
            function (array $chunk) use ($table, $columns, $connection): void {
                $this->insertChunkWithStatement($table, $chunk, $columns, $connection);
            }
        );

        return $results;
    }

    private function bulkInsertChunkSize(int $columnCount, int $requestedChunkSize): int
    {
        $parameterLimit = max(1, (int) env('MIGRATION_BULK_INSERT_PARAMETER_LIMIT', 60000));
        $maxRowsByParameters = max(1, intdiv($parameterLimit, max(1, $columnCount)));

        return max(1, min($requestedChunkSize, $maxRowsByParameters));
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @param array<int, string> $columns
     */
    private function insertChunkWithStatement(string $table, array $chunk, array $columns, string $connection): void
    {
        if (empty($chunk)) {
            return;
        }

        $placeholderGroup = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $placeholderGroups = array_fill(0, count($chunk), $placeholderGroup);
        $bindings = [];

        foreach ($chunk as $row) {
            foreach ($columns as $column) {
                $bindings[] = $this->normalizeBinding($row[$column] ?? null);
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quoteQualifiedIdentifier($table),
            implode(', ', array_map(fn (string $c): string => $this->quoteIdentifier($c), $columns)),
            implode(',', $placeholderGroups)
        );

        Db::connection($connection)->statement($sql, $bindings);
    }

    private function normalizeBinding(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @param callable(array<int, array<string, mixed>>): void $callback
     * @return array{inserted: int, failed: int, errors: array<int, array<string, mixed>>}
     */
    private function runChunkJobs(array $chunks, int $maxCoroutines, callable $callback): array
    {
        $parallel = new Parallel($maxCoroutines);
        $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];

        foreach ($chunks as $index => $chunk) {
            $parallel->add(function () use ($chunk, $index, $callback) {
                try {
                    $callback($chunk);

                    return ['success' => true, 'count' => count($chunk), 'index' => $index];
                } catch (Throwable $e) {
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

    private function quoteQualifiedIdentifier(string $identifier): string
    {
        return implode('.', array_map(
            fn (string $part): string => $this->quoteIdentifier($part),
            explode('.', $identifier)
        ));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warnCopyFallback(string $reason, array $context = []): void
    {
        $key = $reason . ':' . ($context['connection'] ?? '') . ':' . ($context['table'] ?? '');
        if (isset($this->copyWarnings[$key])) {
            return;
        }
        $this->copyWarnings[$key] = true;

        try {
            $logger = ApplicationContext::getContainer()
                ->get(LoggerFactory::class)
                ->get('migration-copy');
            $logger->warning('COPY nativo indisponivel; usando bulk insert otimizado', [
                'reason' => $reason,
                ...$context,
            ]);
        } catch (Throwable) {
            // logging nao deve bloquear a migracao
        }
    }

    private function formatCopyRow(array $record, array $columns, string $sep, string $nullStr): string
    {
        $parts = [];
        foreach ($columns as $col) {
            $val = $record[$col] ?? null;
            if ($val === null) {
                $parts[] = $nullStr;
                continue;
            }
            if (is_bool($val)) {
                $parts[] = $val ? 't' : 'f';
                continue;
            }
            if (is_array($val)) {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = strtr((string) $val, [
                '\\' => '\\\\',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
            ]);
        }
        return implode($sep, $parts);
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
        } catch (Throwable $e) {
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
        $chunkSize = $chunkSize ?: max(1, (int) env('MIGRATION_CHUNK_SIZE', 1000));
        $maxCoroutines = $maxCoroutines ?: max(1, (int) env('MIGRATION_MAX_COROUTINES', 5));

        $records = $this->ensureUuids($records);
        $chunks = array_chunk($records, $chunkSize);
        $parallel = new Parallel($maxCoroutines);
        $results = ['inserted' => 0, 'failed' => 0, 'errors' => []];

        foreach ($chunks as $index => $chunk) {
            $parallel->add(function () use ($table, $chunk, $uniqueKeys, $updateColumns, $index) {
                try {
                    Db::table($table)->upsert($chunk, $uniqueKeys, $updateColumns);
                    return ['success' => true, 'count' => count($chunk), 'index' => $index];
                } catch (Throwable $e) {
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

        $keySet = [];
        foreach ($records as $record) {
            foreach ($record as $key => $_) {
                $keySet[$key] = true;
            }
        }

        $template = array_fill_keys(array_keys($keySet), null);

        foreach ($records as &$record) {
            $record += $template;
        }
        unset($record);

        return $records;
    }
}
