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

namespace HyperfTest\Cases\Unit\Controller\Migration;

use App\Controller\Migration\MigrationJobController;
use App\Exception\ValidationFailedException;
use App\Service\LegacyConnectionFactory;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * @internal
 */
#[CoversClass(MigrationJobController::class)]
final class MigrationJobControllerTest extends UnitTestCase
{
    public function testAvailabilityReturnsAvailableTrueWhenLegacyDatabaseIsReachable(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('legacy_db', '')
            ->willReturn('cont_focons');

        $legacyConnectionFactory = $this->createMock(LegacyConnectionFactory::class);
        $legacyConnectionFactory->expects($this->once())
            ->method('connect')
            ->with('cont_focons')
            ->willReturn('legacy_database_cont_focons');

        $capturedPayload = null;
        $capturedStatus = null;
        $response = $this->createResponseMock($capturedPayload, $capturedStatus);

        $controller = $this->createController($request, $response, $legacyConnectionFactory);
        $result = $controller->availability();

        $this->assertInstanceOf(PsrResponseInterface::class, $result);
        $this->assertSame([
            'legacy_db' => 'cont_focons',
            'available' => true,
            'message' => 'Legacy database is reachable.',
        ], $capturedPayload);
        $this->assertNull($capturedStatus);
    }

    public function testAvailabilityReturnsAvailableFalseWhenLegacyDatabaseIsUnavailable(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('legacy_db', '')
            ->willReturn('cont_krypton');

        $legacyConnectionFactory = $this->createMock(LegacyConnectionFactory::class);
        $legacyConnectionFactory->expects($this->once())
            ->method('connect')
            ->with('cont_krypton')
            ->willThrowException(new ValidationFailedException('Failed to connect to legacy database cont_krypton.'));

        $capturedPayload = null;
        $capturedStatus = null;
        $response = $this->createResponseMock($capturedPayload, $capturedStatus);

        $controller = $this->createController($request, $response, $legacyConnectionFactory);
        $result = $controller->availability();

        $this->assertInstanceOf(PsrResponseInterface::class, $result);
        $this->assertSame([
            'legacy_db' => 'cont_krypton',
            'available' => false,
            'message' => 'Failed to connect to legacy database cont_krypton.',
        ], $capturedPayload);
        $this->assertNull($capturedStatus);
    }

    public function testAvailabilityThrowsValidationFailedWhenLegacyDatabaseIsMissing(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('legacy_db', '')
            ->willReturn('   ');

        $capturedPayload = null;
        $capturedStatus = null;
        $response = $this->createResponseMock($capturedPayload, $capturedStatus);

        $controller = $this->createController(
            $request,
            $response,
            $this->createMock(LegacyConnectionFactory::class)
        );

        $this->expectException(ValidationFailedException::class);
        $this->expectExceptionMessage("The 'legacy_db' field is required.");

        $controller->availability();
    }

    private function createController(
        RequestInterface $request,
        ResponseInterface $response,
        LegacyConnectionFactory $legacyConnectionFactory
    ): MigrationJobController {
        $queue = $this->createMock(DriverInterface::class);

        $driverFactory = $this->createMock(DriverFactory::class);
        $driverFactory->expects($this->once())
            ->method('get')
            ->with('default')
            ->willReturn($queue);

        $controller = new MigrationJobController($driverFactory);
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'response', $response);
        $this->injectProperty($controller, 'legacyConnectionFactory', $legacyConnectionFactory);

        return $controller;
    }
}
