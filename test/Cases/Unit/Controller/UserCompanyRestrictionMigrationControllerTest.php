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

use App\Controller\Migration\UserCompanyRestrictionMigrationController;
use App\Exception\BatchTooLargeException;
use App\Exception\EmptyBatchException;
use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Support\MessageBag;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(UserCompanyRestrictionMigrationController::class)]
final class UserCompanyRestrictionMigrationControllerTest extends UnitTestCase
{
    public function testMigrateThrowsEmptyBatchException(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->with('batch', [])->willReturn([]);

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController($request, $insertService);

        $this->expectException(EmptyBatchException::class);
        $controller->migrate();
    }

    public function testMigrateThrowsBatchTooLargeException(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->with('batch', [])->willReturn(
            array_fill(0, 501, ['legacy_id' => 'X', 'legacy_user_id' => 'U', 'legacy_company_id' => 'C'])
        );

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController($request, $insertService);

        $this->expectException(BatchTooLargeException::class);
        $controller->migrate();
    }

    public function testMigrateResolvesLegacyFKsAndPersistsSyncBatch(): void
    {
        $batch = [[
            'legacy_id' => 'UCR-001',
            'legacy_user_id' => 'USR-123',
            'legacy_company_id' => 'COMP-456',
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
            ->willReturn('contract-1');

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->method('resolveMany')->willReturn([]);
        $idMappingService->method('resolve')
            ->willReturnMap([
                ['users', 'USR-123', 'contract-1', 'user-uuid-1'],
                ['companies', 'COMP-456', 'contract-1', 'company-uuid-1'],
                ['contracts', 'contract-1', 'contract-1', 'contract-uuid-1'],
            ]);

        $persistedRecords = [];
        $generatedId = null;

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertSync')
            ->with(
                'user_company_restrictions',
                $this->callback(function (array $records) use (&$persistedRecords): bool {
                    $persistedRecords = $records;

                    if (count($records) !== 1) {
                        return false;
                    }

                    $record = $records[0];

                    $this->assertArrayNotHasKey('legacy_id', $record);
                    $this->assertArrayNotHasKey('legacy_user_id', $record);
                    $this->assertArrayNotHasKey('legacy_company_id', $record);
                    $this->assertSame('user-uuid-1', $record['user_id']);
                    $this->assertSame('company-uuid-1', $record['company_id']);
                    $this->assertSame('contract-uuid-1', $record['contract_id']);
                    $this->assertMatchesRegularExpression(self::UUID_PATTERN, $record['id']);
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['created_at']);
                    $this->assertSame($record['created_at'], $record['updated_at']);

                    return true;
                }),
                'conciliador_web'
            )
            ->willReturn(['inserted' => 1, 'failed' => 0, 'errors' => []]);

        $idMappingService->expects($this->once())
            ->method('storeBatch')
            ->with(
                'user_company_restrictions',
                $this->callback(function (array $mappings) use (&$generatedId): bool {
                    $generatedId = $mappings['UCR-001'] ?? null;

                    return is_string($generatedId)
                        && preg_match(self::UUID_PATTERN, $generatedId) === 1;
                }),
                'contract-1'
            );

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(false);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->once())
            ->method('make')
            ->willReturn($validator);

        $responseMock = $this->createResponseMock($capturedPayload, $capturedStatus);

        $controller = $this->createController(
            $request,
            $insertService,
            $idMappingService,
            $this->createStub(MigrationBatchService::class),
            $validatorFactory,
            $responseMock
        );

        $controller->migrate();

        $this->assertSame(201, $capturedStatus);
        $this->assertSame(1, $capturedPayload['inserted']);
        $this->assertSame(0, $capturedPayload['skipped']);
        $this->assertSame(0, $capturedPayload['failed']);
        $this->assertSame([], $capturedPayload['errors']);
        $this->assertSame(['UCR-001' => $generatedId], $capturedPayload['id_mappings']);
        $this->assertSame($generatedId, $persistedRecords[0]['id']);
    }

    public function testMigrateSkipsDuplicates(): void
    {
        $batch = [[
            'legacy_id' => 'UCR-001',
            'legacy_user_id' => 'USR-123',
            'legacy_company_id' => 'COMP-456',
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->with('batch', [])->willReturn($batch);
        $request->method('header')->with('X-Contract-Id', '')->willReturn('header-contract');
        $request->method('getAttribute')->with('contract_id', 'header-contract')->willReturn('contract-1');

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->once())
            ->method('resolveMany')
            ->with('user_company_restrictions', ['UCR-001'], 'contract-1')
            ->willReturn(['UCR-001' => 'existing-uuid-1']);
        $idMappingService->expects($this->never())->method('storeBatch');

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $responseMock = $this->createResponseMock($capturedPayload);

        $controller = $this->createController(
            $request,
            $insertService,
            $idMappingService,
            $this->createStub(MigrationBatchService::class),
            $this->createStub(ValidatorFactoryInterface::class),
            $responseMock
        );

        $controller->migrate();

        $this->assertSame(0, $capturedPayload['inserted']);
        $this->assertSame(1, $capturedPayload['skipped']);
        $this->assertSame(0, $capturedPayload['failed']);
        $this->assertSame(['UCR-001' => 'existing-uuid-1'], $capturedPayload['id_mappings']);
    }

    public function testMigrateFailsValidationWhenLegacyUserIdMissing(): void
    {
        $batch = [[
            'legacy_id' => 'UCR-001',
            'legacy_company_id' => 'COMP-456',
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->with('batch', [])->willReturn($batch);
        $request->method('header')->with('X-Contract-Id', '')->willReturn('header-contract');
        $request->method('getAttribute')->with('contract_id', 'header-contract')->willReturn('contract-1');

        $errorsBag = $this->createMock(MessageBag::class);
        $errorsBag->method('toArray')->willReturn(['legacy_user_id' => ['The legacy user id field is required.']]);

        $failValidator = $this->createMock(ValidatorInterface::class);
        $failValidator->method('fails')->willReturn(true);
        $failValidator->method('errors')->willReturn($errorsBag);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->once())->method('make')->willReturn($failValidator);

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $responseMock = $this->createResponseMock($capturedPayload, $capturedStatus);

        $controller = $this->createController(
            $request,
            $insertService,
            $this->createStub(IdMappingService::class),
            $this->createStub(MigrationBatchService::class),
            $validatorFactory,
            $responseMock
        );

        $controller->migrate();

        $this->assertSame(422, $capturedStatus);
        $this->assertSame(0, $capturedPayload['inserted']);
        $this->assertSame(1, $capturedPayload['failed']);
        $this->assertCount(1, $capturedPayload['errors']);
        $this->assertSame('UCR-001', $capturedPayload['errors'][0]['legacy_id']);
    }

    public function testMigrateFailsValidationWhenLegacyCompanyIdMissing(): void
    {
        $batch = [[
            'legacy_id' => 'UCR-001',
            'legacy_user_id' => 'USR-123',
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->with('batch', [])->willReturn($batch);
        $request->method('header')->with('X-Contract-Id', '')->willReturn('header-contract');
        $request->method('getAttribute')->with('contract_id', 'header-contract')->willReturn('contract-1');

        $errorsBag = $this->createMock(MessageBag::class);
        $errorsBag->method('toArray')->willReturn(['legacy_company_id' => ['The legacy company id field is required.']]);

        $failValidator = $this->createMock(ValidatorInterface::class);
        $failValidator->method('fails')->willReturn(true);
        $failValidator->method('errors')->willReturn($errorsBag);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->once())->method('make')->willReturn($failValidator);

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $responseMock = $this->createResponseMock($capturedPayload, $capturedStatus);

        $controller = $this->createController(
            $request,
            $insertService,
            $this->createStub(IdMappingService::class),
            $this->createStub(MigrationBatchService::class),
            $validatorFactory,
            $responseMock
        );

        $controller->migrate();

        $this->assertSame(422, $capturedStatus);
        $this->assertSame(0, $capturedPayload['inserted']);
        $this->assertSame(1, $capturedPayload['failed']);
        $this->assertCount(1, $capturedPayload['errors']);
        $this->assertSame('UCR-001', $capturedPayload['errors'][0]['legacy_id']);
    }

    public function testMigrateReturns207OnMixOfValidAndInvalidRecords(): void
    {
        $batch = [
            [
                'legacy_id' => 'UCR-001',
                'legacy_user_id' => 'USR-123',
                'legacy_company_id' => 'COMP-456',
            ],
            [
                'legacy_id' => 'UCR-002',
                'legacy_company_id' => 'COMP-789',
                // legacy_user_id missing → validation fails
            ],
        ];

        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->with('batch', [])->willReturn($batch);
        $request->method('header')->with('X-Contract-Id', '')->willReturn('header-contract');
        $request->method('getAttribute')->with('contract_id', 'header-contract')->willReturn('contract-1');

        $passValidator = $this->createMock(ValidatorInterface::class);
        $passValidator->method('fails')->willReturn(false);

        $errorsBag = $this->createMock(MessageBag::class);
        $errorsBag->method('toArray')->willReturn(['legacy_user_id' => ['required']]);

        $failValidator = $this->createMock(ValidatorInterface::class);
        $failValidator->method('fails')->willReturn(true);
        $failValidator->method('errors')->willReturn($errorsBag);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->exactly(2))
            ->method('make')
            ->willReturnOnConsecutiveCalls($passValidator, $failValidator);

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->method('resolveMany')->willReturn([]);
        $idMappingService->method('resolve')
            ->willReturnMap([
                ['users', 'USR-123', 'contract-1', 'user-uuid-1'],
                ['companies', 'COMP-456', 'contract-1', 'company-uuid-1'],
                ['contracts', 'contract-1', 'contract-1', 'contract-uuid-1'],
            ]);
        $idMappingService->expects($this->once())->method('storeBatch');

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertSync')
            ->with(
                'user_company_restrictions',
                $this->callback(fn (array $records): bool => count($records) === 1),
                'conciliador_web'
            )
            ->willReturn(['inserted' => 1, 'failed' => 0, 'errors' => []]);

        $responseMock = $this->createResponseMock($capturedPayload, $capturedStatus);

        $controller = $this->createController(
            $request,
            $insertService,
            $idMappingService,
            $this->createStub(MigrationBatchService::class),
            $validatorFactory,
            $responseMock
        );

        $controller->migrate();

        $this->assertSame(207, $capturedStatus);
        $this->assertSame(1, $capturedPayload['inserted']);
        $this->assertSame(1, $capturedPayload['failed']);
    }

    private function createController(
        RequestInterface $request,
        ParallelInsertService $insertService,
        ?IdMappingService $idMappingService = null,
        ?MigrationBatchService $batchService = null,
        ?ValidatorFactoryInterface $validatorFactory = null,
        mixed $responseMock = null
    ): UserCompanyRestrictionMigrationController {
        $controller = new UserCompanyRestrictionMigrationController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'insertService', $insertService);
        $this->injectProperty($controller, 'idMappingService', $idMappingService ?? $this->createStub(IdMappingService::class));
        $this->injectProperty($controller, 'batchService', $batchService ?? $this->createStub(MigrationBatchService::class));
        $this->injectProperty($controller, 'validatorFactory', $validatorFactory ?? $this->createStub(ValidatorFactoryInterface::class));
        if ($responseMock !== null) {
            $this->injectProperty($controller, 'response', $responseMock);
        }

        return $controller;
    }
}
