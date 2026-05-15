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

namespace HyperfTest\Cases\Unit\Middleware;

use App\Middleware\RateLimitMiddleware;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\Redis\Redis;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 */
#[CoversClass(RateLimitMiddleware::class)]
final class RateLimitMiddlewareTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvValue('MIGRATION_RATE_LIMIT', '3');
        $this->setEnvValue('MIGRATION_BULK_RATE_LIMIT', '2');
    }

    public function testProcessStartsRateLimitWindowForFirstStandardRequest(): void
    {
        $redis = $this->createRedisFake(1);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $middleware = new RateLimitMiddleware($redis, $this->createStub(HttpResponse::class));
        $request = new Request(
            'GET',
            new Uri('http://localhost/api/v1/migration/companies'),
            ['X-Contract-Id' => 'contract-1']
        );

        $this->assertSame($expectedResponse, $middleware->process($request, $handler));
        $this->assertSame([
            ['incr', 'migration_rate:contract-1:standard'],
            ['expire', 'migration_rate:contract-1:standard', 60],
        ], $redis->calls);
    }

    public function testProcessIncrementsBulkCounterWhenRequestIsWithinLimit(): void
    {
        $redis = $this->createRedisFake(2);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $middleware = new RateLimitMiddleware($redis, $this->createStub(HttpResponse::class));
        $request = new Request(
            'POST',
            new Uri('http://localhost/api/v1/migration/import-records'),
            ['X-Contract-Id' => 'contract-1']
        );

        $this->assertSame($expectedResponse, $middleware->process($request, $handler));
        $this->assertSame([
            ['incr', 'migration_rate:contract-1:bulk'],
        ], $redis->calls);
    }

    public function testProcessRejectsRequestsThatExceededTheLimit(): void
    {
        $redis = $this->createRedisFake(3, 27);

        [$responseFactory, $response] = $this->mockJsonResponse(
            429,
            static fn (array $payload): bool => $payload === [
                'error' => 'Too Many Requests',
                'message' => 'Rate limit exceeded. Max 2 requests per minute.',
                'retry_after' => 27,
            ]
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware = new RateLimitMiddleware($redis, $responseFactory);
        $request = new Request(
            'POST',
            new Uri('http://localhost/api/v1/migration/import-records'),
            ['X-Contract-Id' => 'contract-1']
        );

        $this->assertSame($response, $middleware->process($request, $handler));
        $this->assertSame([
            ['incr', 'migration_rate:contract-1:bulk'],
            ['ttl', 'migration_rate:contract-1:bulk'],
        ], $redis->calls);
    }

    public function testProcessUsesLegacyDatabaseFromQueryStringAsRateLimitScope(): void
    {
        $redis = $this->createRedisFake(1);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $middleware = new RateLimitMiddleware($redis, $this->createStub(HttpResponse::class));
        $request = (new Request(
            'GET',
            new Uri('http://localhost/api/v1/migration/database/availability?legacy_db=cont_focons')
        ))->withQueryParams(['legacy_db' => 'cont_focons']);

        $this->assertSame($expectedResponse, $middleware->process($request, $handler));
        $this->assertSame([
            ['incr', 'migration_rate:cont_focons:standard'],
            ['expire', 'migration_rate:cont_focons:standard', 60],
        ], $redis->calls);
    }

    /**
     * @return array{0: HttpResponse, 1: ResponseInterface}
     */
    private function mockJsonResponse(int $status, callable $assertPayload): array
    {
        $responseFactory = $this->createMock(HttpResponse::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withStatus')
            ->with($status)
            ->willReturnSelf();

        $responseFactory->expects($this->once())
            ->method('json')
            ->with($this->callback($assertPayload))
            ->willReturn($response);

        return [$responseFactory, $response];
    }

    private function createRedisFake(int $incrResult, int $ttlResult = 0): Redis
    {
        return new class($incrResult, $ttlResult) extends Redis {
            /** @var list<array<int, int|string>> */
            public array $calls = [];

            public function __construct(
                private readonly int $incrResult,
                private readonly int $ttlResult
            ) {
            }

            public function incr(string $key): int
            {
                $this->calls[] = ['incr', $key];

                return $this->incrResult;
            }

            public function expire(string $key, int $seconds): bool
            {
                $this->calls[] = ['expire', $key, $seconds];

                return true;
            }

            public function ttl(string $key): int
            {
                $this->calls[] = ['ttl', $key];

                return $this->ttlResult;
            }
        };
    }
}
