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
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Support\env;

class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly HttpResponse $response
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $migrationScope = $this->resolveMigrationScope($request);
        $path = $request->getUri()->getPath();

        // 'rules-sharings' contém a substring 'rules', por isso comparamos com '/rules'
        // para não aplicar o limite bulk incorretamente ao endpoint síncrono rules-sharings.
        $isBulk = str_contains($path, 'import-records')
            || (str_contains($path, '/rules') && ! str_contains($path, 'rules-sharings'))
            || str_contains($path, 'confrontation-record');

        $limit = $isBulk
            ? (int) env('MIGRATION_BULK_RATE_LIMIT', 30)
            : (int) env('MIGRATION_RATE_LIMIT', 60);

        $key = "migration_rate:{$migrationScope}:" . ($isBulk ? 'bulk' : 'standard');
        $current = (int) $this->redis->incr($key);

        if ($current === 1) {
            $this->redis->expire($key, 60);
        }

        if ($current > $limit) {
            return $this->response->json([
                'error' => 'Too Many Requests',
                'message' => "Rate limit exceeded. Max {$limit} requests per minute.",
                'retry_after' => $this->redis->ttl($key),
            ])->withStatus(429);
        }

        return $handler->handle($request);
    }

    private function resolveMigrationScope(ServerRequestInterface $request): string
    {
        $scope = (string) $request->getAttribute('contract_id', '');

        if ($scope !== '') {
            return $scope;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && ! empty($body['legacy_db'])) {
            return (string) $body['legacy_db'];
        }

        $queryParams = $request->getQueryParams();
        if (! empty($queryParams['legacy_db'])) {
            return (string) $queryParams['legacy_db'];
        }

        $legacyHeader = $request->getHeaderLine('X-Contract-Id');
        if ($legacyHeader !== '') {
            return $legacyHeader;
        }

        return 'anonymous';
    }
}
