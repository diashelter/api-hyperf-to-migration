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
];
