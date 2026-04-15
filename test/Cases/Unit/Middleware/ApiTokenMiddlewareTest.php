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
use Firebase\JWT\JWT;
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

        $this->setEnvValue('JWT_SECRET', 'middleware-secret-key-with-32-bytes');
    }

    public function testProcessRejectsRequestsWithoutBearerToken(): void
    {
        [$responseFactory, $response] = $this->mockJsonResponse(
            401,
            static fn (array $payload): bool => $payload === [
                'error' => 'Unauthorized',
                'message' => 'Missing or invalid Authorization header',
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

    public function testProcessRejectsRequestsWithInvalidToken(): void
    {
        [$responseFactory, $response] = $this->mockJsonResponse(
            401,
            static function (array $payload): bool {
                return $payload['error'] === 'Unauthorized'
                    && str_starts_with($payload['message'], 'Invalid token: ');
            }
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware = new ApiTokenMiddleware($responseFactory);
        $request = new Request(
            'GET',
            new Uri('http://localhost/api/v1/migration/companies'),
            ['Authorization' => 'Bearer invalid-token']
        );

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testProcessRejectsRequestsWhenContractDoesNotMatchToken(): void
    {
        [$responseFactory, $response] = $this->mockJsonResponse(
            403,
            static fn (array $payload): bool => $payload === [
                'error' => 'Forbidden',
                'message' => 'Token contract_id does not match X-Contract-Id header',
            ]
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware = new ApiTokenMiddleware($responseFactory);
        $request = new Request(
            'GET',
            new Uri('http://localhost/api/v1/migration/companies'),
            [
                'Authorization' => 'Bearer ' . $this->createToken('contract-1'),
                'X-Contract-Id' => 'contract-2',
            ]
        );

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testProcessPassesRequestForwardWithResolvedAttributes(): void
    {
        $responseFactory = $this->createStub(HttpResponse::class);
        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Request $request): bool {
                $this->assertSame('user-1', $request->getAttribute('user_id'));
                $this->assertSame('contract-1', $request->getAttribute('contract_id'));

                return true;
            }))
            ->willReturn($expectedResponse);

        $middleware = new ApiTokenMiddleware($responseFactory);
        $request = new Request(
            'GET',
            new Uri('http://localhost/api/v1/migration/companies'),
            [
                'Authorization' => 'Bearer ' . $this->createToken('contract-1'),
                'X-Contract-Id' => 'contract-1',
            ]
        );

        $result = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $result);
    }

    private function createToken(?string $contractId): string
    {
        return JWT::encode([
            'sub' => 'user-1',
            'contract_id' => $contractId,
            'iat' => time(),
            'exp' => time() + 3600,
        ], 'middleware-secret-key-with-32-bytes', 'HS256');
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
