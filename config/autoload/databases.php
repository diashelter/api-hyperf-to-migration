<?php

declare(strict_types=1);

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
            'min_connections' => (int) env('DB_POOL_MIN', 2),
            'max_connections' => (int) env('DB_POOL_MAX', 20),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
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
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
    ],
];
