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
use App\Service\DiscordNotificationService;
use Hyperf\Context\Context;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(
        protected StdoutLoggerInterface $logger,
        protected DiscordNotificationService $discordService,
    ) {
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->logger->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
        $this->logger->error($throwable->getTraceAsString());

        if ($throwable instanceof ApiException) {
            $httpStatus = $throwable->getHttpStatus();
            $this->notifyToDiscord($throwable, $httpStatus);

            return $this->problem(
                $response,
                $httpStatus,
                $throwable->getTitle(),
                $throwable->getDetail(),
                $throwable->getType(),
                $throwable->getErrors(),
            );
        }

        $this->notifyToDiscord($throwable, 500);

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

    private function notifyToDiscord(Throwable $throwable, int $httpStatus): void
    {
        try {
            /** @var null|ServerRequestInterface $request */
            $request = Context::get(ServerRequestInterface::class);
            $contractId = (string) ($request?->getAttribute('contract_id') ?? 'unknown');
            $entity = $this->extractEntityFromUri($request?->getUri()->getPath() ?? '');

            $batchSample = [];
            if ($httpStatus === 500 && $request !== null) {
                $body = $request->getParsedBody();
                $batch = is_array($body) ? ($body['batch'] ?? []) : [];
                $batchSample = array_slice((array) $batch, 0, 5);
            }

            $this->discordService->notifyException($entity, $contractId, $httpStatus, $throwable, $batchSample);
        } catch (Throwable) {
            // Never let notification errors bubble up
        }
    }

    private function extractEntityFromUri(string $path): string
    {
        if (preg_match('#/api/v\d+/migration/([^/?]+)#', $path, $matches)) {
            return $matches[1];
        }

        return 'unknown';
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
