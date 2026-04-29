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

namespace App\Middleware;

use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Support\env;

class ApiTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly HttpResponse $response
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiKey = trim($request->getHeaderLine('X-Api-Key'));

        if ($apiKey === '') {
            return $this->response->json([
                'error' => 'Unauthorized',
                'message' => 'Missing or invalid X-Api-Key header',
            ])->withStatus(401);
        }

        $configuredApiKey = trim((string) env('MIGRATION_API_KEY', ''));

        if ($configuredApiKey === '' || ! hash_equals($configuredApiKey, $apiKey)) {
            return $this->response->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API key',
            ])->withStatus(401);
        }

        return $handler->handle($request);
    }
}
