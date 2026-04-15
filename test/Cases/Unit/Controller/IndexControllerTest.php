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

use App\Controller\IndexController;
use Hyperf\HttpServer\Contract\RequestInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(IndexController::class)]
final class IndexControllerTest extends UnitTestCase
{
    public function testIndexUsesProvidedUserAndMethod(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())->method('input')->with('user', 'Hyperf')->willReturn('Helter');
        $request->expects($this->once())->method('getMethod')->willReturn('POST');

        $controller = new IndexController();
        $this->injectProperty($controller, 'request', $request);

        $this->assertSame(
            ['method' => 'POST', 'message' => 'Hello Helter.'],
            $controller->index()
        );
    }

    public function testIndexFallsBackToDefaultUser(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())->method('input')->with('user', 'Hyperf')->willReturn('Hyperf');
        $request->expects($this->once())->method('getMethod')->willReturn('GET');

        $controller = new IndexController();
        $this->injectProperty($controller, 'request', $request);

        $this->assertSame(
            ['method' => 'GET', 'message' => 'Hello Hyperf.'],
            $controller->index()
        );
    }
}
