<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Parameter(
    parameter: 'X-Contract-Id',
    name: 'X-Contract-Id',
    in: 'header',
    required: true,
    description: 'UUID do contrato (tenant)',
    schema: new OA\Schema(type: 'string', format: 'uuid', example: '660e8400-e29b-41d4-a716-446655440001')
)]
class ContractIdHeader
{
}
