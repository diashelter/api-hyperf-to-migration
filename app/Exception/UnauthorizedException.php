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

class UnauthorizedException extends ApiException
{
    public function __construct(string $detail = 'Authentication failed.')
    {
        parent::__construct(
            detail: $detail,
            httpStatus: 401,
            title: 'Unauthorized',
        );
    }
}
