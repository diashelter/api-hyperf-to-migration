<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ErrorResponse',
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'Empty batch'),
        new OA\Property(property: 'code', type: 'integer', example: 422),
    ]
)]
class ErrorResponse
{
}
