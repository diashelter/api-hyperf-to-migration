<?php

declare(strict_types=1);

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

// Migration API routes are registered via Controller annotations
// Middleware is configured via #[Middleware] annotations on each controller
