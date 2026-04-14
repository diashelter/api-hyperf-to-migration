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

use App\Controller\Migration\MigrationStatusController;
use App\Exception\ResourceNotFoundException;
use App\Exception\ValidationFailedException;
use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use Hyperf\HttpServer\Contract\RequestInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * @internal
 */
#[CoversClass(MigrationStatusController::class)]
final class MigrationStatusControllerTest extends UnitTestCase
{
    public function testShowReturnsNotFoundPayloadWhenBatchDoesNotExist(): void
    {
        $batchService = $this->createMock(MigrationBatchService::class);
        $batchService->expects($this->once())
            ->method('getStatus')
            ->with('batch-1')
            ->willReturn(null);

        $controller = $this->createController(
            $this->createStub(RequestInterface::class),
            $batchService,
            $this->createStub(IdMappingService::class)
        );

        $this->expectException(ResourceNotFoundException::class);
        $controller->show('batch-1');
    }

    public function testShowReturnsBatchStatusWhenItExists(): void
    {
        $status = ['migration_batch_id' => 'batch-1', 'status' => 'completed'];

        $batchService = $this->createMock(MigrationBatchService::class);
        $batchService->expects($this->once())
            ->method('getStatus')
            ->with('batch-1')
            ->willReturn($status);

        $responseMock = $this->createResponseMock($capturedPayload);
        $controller = $this->createController(
            $this->createStub(RequestInterface::class),
            $batchService,
            $this->createStub(IdMappingService::class),
            $responseMock
        );

        $result = $controller->show('batch-1');

        $this->assertInstanceOf(PsrResponseInterface::class, $result);
        $this->assertSame($status, $capturedPayload);
    }

    public function testIndexListsBatchesUsingResolvedContractId(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('header')
            ->with('X-Contract-Id', '')
            ->willReturn('header-contract');
        $request->expects($this->once())
            ->method('getAttribute')
            ->with('contract_id', 'header-contract')
            ->willReturn('contract-1');

        $batchService = $this->createMock(MigrationBatchService::class);
        $batchService->expects($this->once())
            ->method('listByContract')
            ->with('contract-1')
            ->willReturn([['id' => 'batch-1']]);

        $responseMock = $this->createResponseMock($capturedPayload);
        $controller = $this->createController(
            $request,
            $batchService,
            $this->createStub(IdMappingService::class),
            $responseMock
        );

        $result = $controller->index();

        $this->assertInstanceOf(PsrResponseInterface::class, $result);
        $this->assertSame([['id' => 'batch-1']], $capturedPayload);
    }

    public function testIdMappingValidatesRequiredParameters(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'entity' => '',
                    'legacy_ids' => [],
                    default => $default,
                };
            }
        );

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->never())->method('resolveMany');

        $controller = $this->createController(
            $request,
            $this->createStub(MigrationBatchService::class),
            $idMappingService
        );

        $this->expectException(ValidationFailedException::class);
        $controller->idMapping();
    }

    public function testIdMappingReturnsMappingsWhenParametersAreValid(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'entity' => 'companies',
                    'legacy_ids' => ['legacy-1', 'legacy-2'],
                    default => $default,
                };
            }
        );
        $request->expects($this->once())
            ->method('header')
            ->with('X-Contract-Id', '')
            ->willReturn('header-contract');
        $request->expects($this->once())
            ->method('getAttribute')
            ->with('contract_id', 'header-contract')
            ->willReturn('contract-1');

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->once())
            ->method('resolveMany')
            ->with('companies', ['legacy-1', 'legacy-2'], 'contract-1')
            ->willReturn(['legacy-1' => 'uuid-1']);

        $responseMock = $this->createResponseMock($capturedPayload);
        $controller = $this->createController(
            $request,
            $this->createStub(MigrationBatchService::class),
            $idMappingService,
            $responseMock
        );

        $result = $controller->idMapping();

        $this->assertInstanceOf(PsrResponseInterface::class, $result);
        $this->assertSame(['mappings' => ['legacy-1' => 'uuid-1']], $capturedPayload);
    }

    private function createController(
        RequestInterface $request,
        MigrationBatchService $batchService,
        IdMappingService $idMappingService,
        mixed $responseMock = null
    ): MigrationStatusController {
        $controller = new MigrationStatusController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'batchService', $batchService);
        $this->injectProperty($controller, 'idMappingService', $idMappingService);
        if ($responseMock !== null) {
            $this->injectProperty($controller, 'response', $responseMock);
        }

        return $controller;
    }
}
