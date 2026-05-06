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
use function Hyperf\Support\env;

return [
    'default' => [
        'driver' => env('DB_DRIVER', 'pgsql'),
        'host' => env('DB_HOST', 'postgres'),
        'database' => env('DB_DATABASE', 'conciliador'),
        'port' => (int) env('DB_PORT', 5432),
        'username' => env('DB_USERNAME', 'conciliador'),
        'password' => env('DB_PASSWORD', 'conciliador'),
        'charset' => env('DB_CHARSET', 'utf8'),
        'collation' => env('DB_COLLATION', 'utf8_unicode_ci'),
        'prefix' => env('DB_PREFIX', ''),
        'schema' => 'public',
        'pool' => [
            'min_connections' => (int) env('DB_POOL_MIN', 8),
            'max_connections' => (int) env('DB_POOL_MAX', 64),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 180),
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
            ],
        ],
    ],

    'conciliador_web' => [
        'driver' => env('DB_WEB_DRIVER', 'pgsql'),
        'host' => env('DB_WEB_HOST', 'postgres'),
        'database' => env('DB_WEB_DATABASE', 'conciliador'),
        'port' => (int) env('DB_WEB_PORT', 5432),
        'username' => env('DB_WEB_USERNAME', 'postgres'),
        'password' => env('DB_WEB_PASSWORD', 'conciliador'),
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
        'pool' => [
            'min_connections' => (int) env('DB_WEB_POOL_MIN', 8),
            'max_connections' => (int) env('DB_WEB_POOL_MAX', 64),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('DB_WEB_MAX_IDLE_TIME', 180),
        ],
    ],

    'legacy_database' => [
        'driver' => env('DB_LEGACY_DRIVER', 'pgsql'),
        'host' => env('DB_LEGACY_HOST', 'postgres'),
        'database' => env('DB_LEGACY_DATABASE', null), // Será sobrescrito dinamicamente
        'port' => (int) env('DB_LEGACY_PORT', 5432),
        'username' => env('DB_LEGACY_USERNAME', 'postgres'),
        'password' => env('DB_LEGACY_PASSWORD', 'conciliador'),
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
        'pool' => [
            'min_connections' => (int) env('DB_LEGACY_POOL_MIN', 4),
            'max_connections' => (int) env('DB_LEGACY_POOL_MAX', 32),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('DB_LEGACY_MAX_IDLE_TIME', 180),
        ],
    ],
];
