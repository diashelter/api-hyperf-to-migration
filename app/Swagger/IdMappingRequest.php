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
    schema: 'IdMappingRequest',
    required: ['entity', 'legacy_ids'],
    properties: [
        new OA\Property(property: 'entity', type: 'string', example: 'users', description: 'Nome da entidade'),
        new OA\Property(
            property: 'legacy_ids',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['LEG-001', 'LEG-002', 'LEG-003'],
            description: 'Array de IDs legados para consultar'
        ),
    ]
)]
class IdMappingRequest
{
}
