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

class BatchTooLargeException extends ApiException
{
    public function __construct(int $max)
    {
        parent::__construct(
            detail: "Batch size exceeds the maximum allowed of {$max} records.",
            httpStatus: 413,
            title: 'Content Too Large',
        );
    }
}
