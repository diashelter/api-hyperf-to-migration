<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AsyncMigrationResponse',
    properties: [
        new OA\Property(property: 'migration_batch_id', type: 'string', format: 'uuid', example: '770e8400-e29b-41d4-a716-446655440002'),
        new OA\Property(property: 'entity', type: 'string', example: 'import_records'),
        new OA\Property(property: 'total_received', type: 'integer', example: 2000),
        new OA\Property(property: 'status', type: 'string', enum: ['completed', 'completed_with_errors'], example: 'completed'),
        new OA\Property(property: 'inserted', type: 'integer', example: 1995),
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
            additionalProperties: new OA\AdditionalProperties(type: 'string')
        ),
        new OA\Property(property: 'status_url', type: 'string', example: '/api/v1/migration/status/770e8400-e29b-41d4-a716-446655440002'),
    ]
)]
class AsyncMigrationResponse
{
}
