<?php

declare(strict_types=1);

return [
    'http' => [
        // Global middlewares are applied to all routes.
        // Route-specific middlewares (ApiToken, RateLimit) are
        // configured in config/routes.php via addGroup().
    ],
];
