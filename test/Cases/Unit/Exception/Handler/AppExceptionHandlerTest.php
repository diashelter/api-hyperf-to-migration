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

use App\Exception\ApiException;
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
    public function testHandleLogsAndReturns500ForGenericException(): void
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
            ->with('Content-Type', 'application/problem+json')
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('withStatus')
            ->with(500)
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('withBody')
            ->with($this->callback(function (SwooleStream $stream): bool {
                $decoded = json_decode($stream->getContents(), true);
                return isset($decoded['type'], $decoded['title'], $decoded['status'], $decoded['detail'])
                    && $decoded['status'] === 500
                    && $decoded['title'] === 'Internal Server Error';
            }))
            ->willReturnSelf();

        $handler = new AppExceptionHandler($logger);
        $result = $handler->handle($exception, $response);

        $this->assertSame($response, $result);
    }

    public function testHandleReturnsApiExceptionStatusAndBody(): void
    {
        $exception = new ApiException('Record not found.', 404, 'Not Found');
        $logger = $this->createMock(StdoutLoggerInterface::class);
        $logger->method('error');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('withHeader')->with('Content-Type', 'application/problem+json')->willReturnSelf();
        $response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();
        $response->expects($this->once())
            ->method('withBody')
            ->with($this->callback(function (SwooleStream $stream): bool {
                $decoded = json_decode($stream->getContents(), true);
                return $decoded['status'] === 404
                    && $decoded['title'] === 'Not Found'
                    && $decoded['detail'] === 'Record not found.';
            }))
            ->willReturnSelf();

        $handler = new AppExceptionHandler($logger);
        $handler->handle($exception, $response);
    }

    public function testIsValidAlwaysReturnsTrue(): void
    {
        $handler = new AppExceptionHandler($this->createStub(StdoutLoggerInterface::class));

        $this->assertTrue($handler->isValid($this->createMock(Throwable::class)));
    }
}
