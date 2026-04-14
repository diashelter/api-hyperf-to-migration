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

use App\Controller\Migration\UserMigrationController;
use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use App\Exception\BatchTooLargeException;
use App\Exception\EmptyBatchException;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Support\MessageBag;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(UserMigrationController::class)]
final class UserMigrationControllerTest extends UnitTestCase
{
    public function testMigrateReturnsErrorForEmptyBatch(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn([]);

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController($request, $insertService);

        $this->expectException(EmptyBatchException::class);
        $controller->migrate();
    }

    public function testMigrateReturnsErrorWhenBatchExceedsLimit(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn(array_fill(0, 201, ['name' => 'User']));

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController($request, $insertService);

        $this->expectException(BatchTooLargeException::class);
        $controller->migrate();
    }

    public function testMigrateHashesPasswordBeforeInserting(): void
    {
        $rawPassword = 'senha_legado_123';
        $batch = [[
            'legacy_id' => 'USR-001',
            'name' => 'João Silva',
            'email' => 'joao.silva@empresa.com',
            'password' => $rawPassword,
            'is_admin' => false,
            'is_internal' => false,
            'status' => true,
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn($batch);
        $request->expects($this->once())
            ->method('header')
            ->with('X-Contract-Id', '')
            ->willReturn('header-contract');
        $request->expects($this->once())
            ->method('getAttribute')
            ->with('contract_id', 'header-contract')
            ->willReturn('contract-uuid-1');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(false);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->method('make')->willReturn($validator);

        $persistedRecords = [];

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertSync')
            ->with(
                'users',
                $this->callback(function (array $records) use ($rawPassword, &$persistedRecords): bool {
                    $persistedRecords = $records;
                    $record = $records[0];

                    $this->assertArrayNotHasKey('legacy_id', $record);
                    $this->assertSame('JOÃO SILVA', $record['name']);
                    $this->assertSame('joao.silva@empresa.com', $record['email']);
                    $this->assertNotSame($rawPassword, $record['password']);
                    $this->assertTrue(password_verify($rawPassword, $record['password']));
                    $this->assertMatchesRegularExpression(self::UUID_PATTERN, $record['id']);
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['created_at']);

                    return true;
                }),
                'conciliador_web'
            )
            ->willReturn(['inserted' => 1, 'failed' => 0, 'errors' => []]);

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->once())
            ->method('storeBatch')
            ->with(
                'users',
                $this->callback(function (array $mappings): bool {
                    return isset($mappings['USR-001'])
                        && preg_match(self::UUID_PATTERN, $mappings['USR-001']) === 1;
                }),
                'contract-uuid-1'
            );

        $responseMock = $this->createResponseMock($capturedPayload);
        $controller = $this->createController($request, $insertService, $idMappingService, null, $validatorFactory);
        $this->injectProperty($controller, 'response', $responseMock);

        $controller->migrate();

        $this->assertSame(1, $capturedPayload['inserted']);
        $this->assertSame(0, $capturedPayload['failed']);
        $this->assertSame([], $capturedPayload['errors']);
        $this->assertArrayHasKey('USR-001', $capturedPayload['id_mappings']);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $capturedPayload['id_mappings']['USR-001']);
        $this->assertSame($persistedRecords[0]['id'], $capturedPayload['id_mappings']['USR-001']);
    }

    public function testMigrateReportsValidationErrorForMissingEmail(): void
    {
        $batch = [[
            'legacy_id' => 'USR-BAD',
            'name' => 'Usuário Inválido',
            'password' => 'senha123',
            // missing: email
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn($batch);
        $request->method('header')->willReturn('contract-1');
        $request->method('getAttribute')->willReturn('contract-1');

        $messageBag = $this->createMock(MessageBag::class);
        $messageBag->method('toArray')->willReturn([
            'email' => ['The email field is required.'],
        ]);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(true);
        $validator->method('errors')->willReturn($messageBag);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->method('make')->willReturn($validator);

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $responseMock = $this->createResponseMock($capturedPayload);
        $controller = $this->createController($request, $insertService, null, null, $validatorFactory);
        $this->injectProperty($controller, 'response', $responseMock);

        $controller->migrate();

        $this->assertSame(0, $capturedPayload['inserted']);
        $this->assertSame(1, $capturedPayload['failed']);
        $this->assertCount(1, $capturedPayload['errors']);
        $this->assertSame(0, $capturedPayload['errors'][0]['index']);
        $this->assertSame('USR-BAD', $capturedPayload['errors'][0]['legacy_id']);
        $this->assertArrayHasKey('email', $capturedPayload['errors'][0]['validation_errors']);
    }

    private function createController(
        RequestInterface $request,
        ParallelInsertService $insertService,
        ?IdMappingService $idMappingService = null,
        ?MigrationBatchService $batchService = null,
        ?ValidatorFactoryInterface $validatorFactory = null
    ): UserMigrationController {
        $controller = new UserMigrationController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'insertService', $insertService);
        $this->injectProperty($controller, 'idMappingService', $idMappingService ?? $this->createStub(IdMappingService::class));
        $this->injectProperty($controller, 'batchService', $batchService ?? $this->createStub(MigrationBatchService::class));
        $this->injectProperty($controller, 'validatorFactory', $validatorFactory ?? $this->createStub(ValidatorFactoryInterface::class));

        return $controller;
    }
}
