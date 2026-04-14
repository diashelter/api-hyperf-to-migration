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

use RuntimeException;

/**
 * Base exception for all API domain errors.
 * Caught by AppExceptionHandler and rendered as RFC 7807 problem+json.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        private readonly string $detail,
        private readonly int $httpStatus,
        private readonly string $title,
        private readonly string $type = 'about:blank',
        private readonly array $errors = [],
    ) {
        parent::__construct($detail);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
