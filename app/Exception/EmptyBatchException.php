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

class EmptyBatchException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            detail: "The 'batch' field is required and must not be empty.",
            httpStatus: 422,
            title: 'Unprocessable Entity',
        );
    }
}
