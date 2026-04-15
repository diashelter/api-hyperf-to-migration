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
    schema: 'TokenRequest',
    required: ['user_id', 'contract_id', 'secret'],
    properties: [
        new OA\Property(property: 'user_id', type: 'string', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'contract_id', type: 'string', example: '660e8400-e29b-41d4-a716-446655440001'),
        new OA\Property(property: 'secret', type: 'string', example: 'your-jwt-secret'),
    ]
)]
class TokenRequest
{
}
