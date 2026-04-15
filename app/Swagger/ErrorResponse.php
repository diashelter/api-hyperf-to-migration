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

/**
 * RFC 7807 Problem Details schema.
 * Registered as both "ProblemResponse" and "ErrorResponse" (alias for backwards compatibility).
 */
#[OA\Schema(
    schema: 'ProblemResponse',
    description: 'RFC 7807 Problem Details for HTTP APIs (application/problem+json)',
    required: ['type', 'title', 'status', 'detail'],
    properties: [
        new OA\Property(
            property: 'type',
            type: 'string',
            description: 'URI reference identifying the problem type',
            example: 'about:blank'
        ),
        new OA\Property(
            property: 'title',
            type: 'string',
            description: 'Short, human-readable summary of the problem type',
            example: 'Unprocessable Entity'
        ),
        new OA\Property(
            property: 'status',
            type: 'integer',
            description: 'HTTP status code',
            example: 422
        ),
        new OA\Property(
            property: 'detail',
            type: 'string',
            description: 'Human-readable explanation specific to this occurrence',
            example: "The 'batch' field is required and must not be empty."
        ),
        new OA\Property(
            property: 'errors',
            type: 'array',
            nullable: true,
            description: 'Optional list of per-record or per-field errors',
            items: new OA\Items(type: 'object')
        ),
    ]
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    description: 'Alias of ProblemResponse (backwards compatibility)',
    allOf: [new OA\Schema(ref: '#/components/schemas/ProblemResponse')]
)]
class ErrorResponse
{
}
