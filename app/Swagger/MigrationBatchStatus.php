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
    schema: 'MigrationBatchStatus',
    properties: [
        new OA\Property(property: 'migration_batch_id', type: 'string', format: 'uuid', example: '770e8400-e29b-41d4-a716-446655440002'),
        new OA\Property(property: 'entity', type: 'string', example: 'import_records'),
        new OA\Property(property: 'status', type: 'string', enum: ['queued', 'processing', 'completed', 'completed_with_errors', 'failed'], example: 'completed'),
        new OA\Property(property: 'total_records', type: 'integer', example: 2000),
        new OA\Property(property: 'processed_records', type: 'integer', example: 1995),
        new OA\Property(property: 'failed_records', type: 'integer', example: 5),
        new OA\Property(property: 'progress_percentage', type: 'number', format: 'float', example: 99.75),
        new OA\Property(
            property: 'errors',
            type: 'array',
            nullable: true,
            items: new OA\Items(type: 'object')
        ),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class MigrationBatchStatus
{
}
