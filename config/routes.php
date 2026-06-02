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

use Hyperf\HttpServer\Router\Router;

// Health check
Router::get('/', function () {
    return [
        'service' => 'conciliador-migrator',
        'status' => 'running',
        'version' => '1.0.0',
    ];
});

Router::get('/health', function () {
    return ['status' => 'ok', 'timestamp' => date('c')];
});
