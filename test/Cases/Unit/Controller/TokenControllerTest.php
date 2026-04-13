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

namespace HyperfTest\Cases\Unit\Controller;

use App\Controller\Migration\TokenController;
use App\Service\TokenService;
use Hyperf\HttpServer\Contract\RequestInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(TokenController::class)]
final class TokenControllerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvValue('JWT_SECRET', 'controller-secret-key-with-32-bytes');
        $this->setEnvValue('JWT_TTL', '1800');
    }

    public function testGenerateReturnsUnauthorizedPayloadWhenSecretIsInvalid(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'user_id' => 'user-1',
                    'contract_id' => 'contract-1',
                    'secret' => 'wrong-secret',
                    default => $default,
                };
            }
        );

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('generate');

        $controller = $this->createController($request, $tokenService);

        $this->assertSame(
            ['error' => 'Invalid secret', 'code' => 401],
            $controller->generate()
        );
    }

    public function testGenerateReturnsTokenPayloadWhenSecretIsValid(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'user_id' => 'user-1',
                    'contract_id' => 'contract-1',
                    'secret' => 'controller-secret-key-with-32-bytes',
                    default => $default,
                };
            }
        );

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('generate')
            ->with('user-1', 'contract-1')
            ->willReturn('jwt-token');

        $controller = $this->createController($request, $tokenService);

        $this->assertSame(
            [
                'token' => 'jwt-token',
                'type' => 'Bearer',
                'expires_in' => 1800,
            ],
            $controller->generate()
        );
    }

    private function createController(RequestInterface $request, TokenService $tokenService): TokenController
    {
        $controller = new TokenController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'tokenService', $tokenService);

        return $controller;
    }
}
