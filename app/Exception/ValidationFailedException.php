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

namespace App\Exception;

class ValidationFailedException extends ApiException
{
    public function __construct(string $detail, array $errors = [])
    {
        parent::__construct(
            detail: $detail,
            httpStatus: 422,
            title: 'Unprocessable Entity',
            errors: $errors,
        );
    }
}
