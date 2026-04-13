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
    schema: 'ValidationErrorResponse',
    description: 'Erro de validação por registro dentro do batch. Retornado em errors[] quando um registro não passa na validação.',
    properties: [
        new OA\Property(property: 'index', type: 'integer', description: 'Posição do registro no array batch (0-based)', example: 2),
        new OA\Property(property: 'legacy_id', type: 'string', nullable: true, description: 'legacy_id do registro com erro, se presente', example: 'LEG-003'),
        new OA\Property(
            property: 'validation_errors',
            type: 'object',
            description: 'Erros por campo (chave = nome do campo, valor = array de mensagens)',
            example: ['cpf_cnpj' => ['The cpf_cnpj field is required.'], 'email' => ['The email must be a valid email address.']]
        ),
    ]
)]
class ValidationErrorResponse
{
}
