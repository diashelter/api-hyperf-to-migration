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
use Hyperf\AsyncQueue\Driver\RedisDriver;
use Hyperf\AsyncQueue\Handler\FailedHandler;

use function Hyperf\Support\env;

return [
    'default' => [
        'driver' => RedisDriver::class,
        'redis' => [
            'pool' => 'default',
        ],
        'channel' => '{conciliador-migrator-queue}',
        'timeout' => 2,
        'retry_seconds' => 5,
        // Migrações longas: até 24h por job para tabelas com >1M linhas.
        'handle_timeout' => (int) env('MIGRATION_QUEUE_HANDLE_TIMEOUT', 86400),
        // Um único consumer process — workers paralelos quebrariam a ordem de FK.
        'processes' => (int) env('MIGRATION_QUEUE_PROCESSES', 1),
        // 1 job pull-mode em voo por vez. Cada job já paraleliza inserts
        // dentro das entidades via Swoole Parallel.
        'concurrent' => [
            'limit' => (int) env('MIGRATION_QUEUE_CONCURRENT', 1),
        ],
        'max_messages' => 0,
        'failed_handler' => FailedHandler::class,
    ],
];
