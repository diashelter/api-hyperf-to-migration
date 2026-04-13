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
