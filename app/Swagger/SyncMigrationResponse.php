<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SyncMigrationResponse',
    properties: [
        new OA\Property(property: 'inserted', type: 'integer', example: 95),
        new OA\Property(property: 'skipped', type: 'integer', example: 12, description: 'Registros ignorados por já possuírem mapping/idempotência.'),
        new OA\Property(property: 'failed', type: 'integer', example: 5),
        new OA\Property(
            property: 'errors',
            type: 'array',
            nullable: true,
            description: 'Erros por registro. Cada item pode ser um erro de validação (com validation_errors) ou erro de insert no banco (com error).',
            items: new OA\Items(
                oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ValidationErrorResponse'),
                    new OA\Schema(
                        properties: [
                            new OA\Property(property: 'index', type: 'integer', example: 3),
                            new OA\Property(property: 'error', type: 'string', example: 'Duplicate entry for key'),
                        ]
                    ),
                ]
            )
        ),
        new OA\Property(
            property: 'id_mappings',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'string'),
            example: ['LEG-001' => '550e8400-e29b-41d4-a716-446655440000', 'LEG-002' => '660e8400-e29b-41d4-a716-446655440001'],
            description: 'Mapeamento legacy_id → novo UUID'
        ),
    ]
)]
class SyncMigrationResponse
{
}
