<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RateLimitResponse',
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'Rate limit exceeded'),
        new OA\Property(property: 'retry_after', type: 'integer', example: 45),
    ]
)]
class RateLimitResponse
{
}
