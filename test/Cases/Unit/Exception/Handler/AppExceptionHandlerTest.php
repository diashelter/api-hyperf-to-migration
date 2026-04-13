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

namespace HyperfTest\Cases\Unit\Exception\Handler;

use App\Exception\Handler\AppExceptionHandler;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(AppExceptionHandler::class)]
final class AppExceptionHandlerTest extends UnitTestCase
{
    public function testHandleLogsTheExceptionAndReturnsInternalServerErrorResponse(): void
    {
        $exception = new RuntimeException('Boom');
        $logger = $this->createMock(StdoutLoggerInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $logger->expects($this->exactly(2))
            ->method('error')
            ->with($this->callback(function (string $message) use ($exception): bool {
                return str_contains($message, $exception->getMessage())
                    || $message === $exception->getTraceAsString();
            }));

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Server', 'Hyperf')
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('withStatus')
            ->with(500)
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('withBody')
            ->with($this->callback(function (SwooleStream $stream): bool {
                return $stream->getContents() === 'Internal Server Error.';
            }))
            ->willReturnSelf();

        $handler = new AppExceptionHandler($logger);
        $result = $handler->handle($exception, $response);

        $this->assertSame($response, $result);
    }

    public function testIsValidAlwaysReturnsTrue(): void
    {
        $handler = new AppExceptionHandler($this->createStub(StdoutLoggerInterface::class));

        $this->assertTrue($handler->isValid($this->createMock(Throwable::class)));
    }
}
