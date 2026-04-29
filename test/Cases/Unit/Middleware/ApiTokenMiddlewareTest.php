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

use App\Middleware\ApiTokenMiddleware;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 */
#[CoversClass(ApiTokenMiddleware::class)]
final class ApiTokenMiddlewareTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvValue('MIGRATION_API_KEY', 'middleware-api-key');
    }

    public function testProcessRejectsRequestsWithoutApiKey(): void
    {
        [$responseFactory, $response] = $this->mockJsonResponse(
            401,
            static fn (array $payload): bool => $payload === [
                'error' => 'Unauthorized',
                'message' => 'Missing or invalid X-Api-Key header',
            ]
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware = new ApiTokenMiddleware($responseFactory);

        $result = $middleware->process(
            new Request('GET', new Uri('http://localhost/api/v1/migration/companies')),
            $handler
        );

        $this->assertSame($response, $result);
    }

    public function testProcessRejectsRequestsWithInvalidApiKey(): void
    {
        [$responseFactory, $response] = $this->mockJsonResponse(
            401,
            static fn (array $payload): bool => $payload === [
                'error' => 'Unauthorized',
                'message' => 'Invalid API key',
            ]
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware = new ApiTokenMiddleware($responseFactory);
        $request = new Request(
            'GET',
            new Uri('http://localhost/api/v1/migration/companies'),
            ['X-Api-Key' => 'invalid-api-key']
        );

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testProcessRejectsRequestsWhenConfiguredApiKeyIsMissing(): void
    {
        [$responseFactory, $response] = $this->mockJsonResponse(
            401,
            static fn (array $payload): bool => $payload === [
                'error' => 'Unauthorized',
                'message' => 'Invalid API key',
            ]
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->setEnvValue('MIGRATION_API_KEY', null);

        $middleware = new ApiTokenMiddleware($responseFactory);
        $request = new Request(
            'GET',
            new Uri('http://localhost/api/v1/migration/companies'),
            ['X-Api-Key' => 'middleware-api-key']
        );

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testProcessPassesRequestForwardWithValidApiKey(): void
    {
        $responseFactory = $this->createStub(HttpResponse::class);
        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($this->isInstanceOf(Request::class))
            ->willReturn($expectedResponse);

        $middleware = new ApiTokenMiddleware($responseFactory);
        $request = new Request(
            'GET',
            new Uri('http://localhost/api/v1/migration/companies'),
            ['X-Api-Key' => 'middleware-api-key']
        );

        $result = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $result);
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
}
