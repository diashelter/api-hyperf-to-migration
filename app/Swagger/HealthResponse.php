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

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'HealthResponse',
    properties: [
        new OA\Property(property: 'service', type: 'string', example: 'conciliador-migrator'),
        new OA\Property(property: 'status', type: 'string', example: 'running'),
        new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
    ]
)]
class HealthResponse
{
}
