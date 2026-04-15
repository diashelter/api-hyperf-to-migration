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

class ResourceNotFoundException extends ApiException
{
    public function __construct(string $detail = 'The requested resource was not found.')
    {
        parent::__construct(
            detail: $detail,
            httpStatus: 404,
            title: 'Not Found',
        );
    }
}
