<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MigrationBatchRequest',
    required: ['batch'],
    properties: [
        new OA\Property(
            property: 'batch',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'legacy_id', type: 'string', nullable: true, description: 'ID do registro no sistema legado (opcional)', example: 'LEG-001'),
                    new OA\Property(property: 'id', type: 'string', nullable: true, description: 'UUID para o novo registro (gerado automaticamente se vazio)'),
                ],
                additionalProperties: true
            ),
            description: 'Array de registros para migrar'
        ),
    ]
)]
class MigrationBatchRequest
{
}
