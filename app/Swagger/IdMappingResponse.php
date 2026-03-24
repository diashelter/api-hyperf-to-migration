<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'IdMappingResponse',
    properties: [
        new OA\Property(
            property: 'mappings',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'string', nullable: true),
            example: ['LEG-001' => '550e8400-e29b-41d4-a716-446655440000', 'LEG-002' => null],
            description: 'Mapeamento legacy_id → UUID (null se não encontrado)'
        ),
    ]
)]
class IdMappingResponse
{
}
