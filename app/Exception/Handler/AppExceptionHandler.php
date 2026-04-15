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

namespace App\Exception\Handler;

use App\Exception\ApiException;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->logger->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
        $this->logger->error($throwable->getTraceAsString());

        if ($throwable instanceof ApiException) {
            return $this->problem(
                $response,
                $throwable->getHttpStatus(),
                $throwable->getTitle(),
                $throwable->getDetail(),
                $throwable->getType(),
                $throwable->getErrors(),
            );
        }

        return $this->problem(
            $response,
            500,
            'Internal Server Error',
            'An unexpected error occurred. Please try again later.',
        );
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }

    private function problem(
        ResponseInterface $response,
        int $status,
        string $title,
        string $detail,
        string $type = 'about:blank',
        array $errors = [],
    ): ResponseInterface {
        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ];

        if (! empty($errors)) {
            $body['errors'] = $errors;
        }

        return $response
            ->withHeader('Content-Type', 'application/problem+json')
            ->withStatus($status)
            ->withBody(new SwooleStream((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
}
