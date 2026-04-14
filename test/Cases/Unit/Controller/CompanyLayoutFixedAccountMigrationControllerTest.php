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

use App\Controller\Migration\CompanyLayoutFixedAccountMigrationController;
use App\Exception\BatchTooLargeException;
use App\Exception\EmptyBatchException;
use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(CompanyLayoutFixedAccountMigrationController::class)]
final class CompanyLayoutFixedAccountMigrationControllerTest extends UnitTestCase
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
        $request->method('input')->with('batch', [])->willReturn(array_fill(0, 201, ['legacy_company_layout_id' => 'CL-001']));

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController($request, $insertService);

        $this->expectException(BatchTooLargeException::class);
        $controller->migrate();
    }

    public function testMigrateTransformsAndPersistsSyncBatch(): void
    {
        $batch = [[
            'legacy_id' => 'CFA-001',
            'legacy_company_layout_id' => 'CL-001',
            'bank' => 'BRADESCO',
            'is_default_account' => false,
            'value_debit' => '1.1.01',
            'value_code_history_debit' => '001',
            'value_history_debit' => 'RECEBIMENTO',
            'value_credit' => '4.1.01',
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
        $idMappingService->expects($this->once())
            ->method('resolve')
            ->with('company_layout', 'CL-001', 'contract-1')
            ->willReturn('company-layout-uuid-1');

        $persistedRecords = [];
        $generatedId = null;

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertSync')
            ->with(
                'company_layout_fixed_accounts',
                $this->callback(function (array $records) use (&$persistedRecords): bool {
                    $persistedRecords = $records;

                    if (count($records) !== 1) {
                        return false;
                    }

                    $record = $records[0];

                    $this->assertArrayNotHasKey('legacy_id', $record);
                    $this->assertArrayNotHasKey('legacy_company_layout_id', $record);
                    $this->assertSame('company-layout-uuid-1', $record['company_layout_id']);
                    $this->assertSame('BRADESCO', $record['bank']);
                    $this->assertFalse($record['is_default_account']);
                    $this->assertSame('1.1.01', $record['value_debit']);
                    $this->assertSame('001', $record['value_code_history_debit']);
                    $this->assertSame('RECEBIMENTO', $record['value_history_debit']);
                    $this->assertSame('4.1.01', $record['value_credit']);
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
                'company_layout_fixed_accounts',
                $this->callback(function (array $mappings) use (&$generatedId): bool {
                    $generatedId = $mappings['CFA-001'] ?? null;

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

        $responseMock = $this->createResponseMock($capturedPayload);

        $controller = $this->createController(
            $request,
            $insertService,
            $idMappingService,
            $this->createStub(MigrationBatchService::class),
            $validatorFactory,
            $responseMock
        );

        $controller->migrate();

        $this->assertSame(1, $capturedPayload['inserted']);
        $this->assertSame(0, $capturedPayload['failed']);
        $this->assertSame([], $capturedPayload['errors']);
        $this->assertSame(['CFA-001' => $generatedId], $capturedPayload['id_mappings']);
        $this->assertSame($generatedId, $persistedRecords[0]['id']);
    }

    public function testMigrateSkipsDuplicates(): void
    {
        $batch = [[
            'legacy_id' => 'CFA-001',
            'legacy_company_layout_id' => 'CL-001',
            'value_debit' => '1.1.01',
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->method('input')->with('batch', [])->willReturn($batch);
        $request->method('header')->with('X-Contract-Id', '')->willReturn('header-contract');
        $request->method('getAttribute')->with('contract_id', 'header-contract')->willReturn('contract-1');

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->once())
            ->method('resolveMany')
            ->with('company_layout_fixed_accounts', ['CFA-001'], 'contract-1')
            ->willReturn(['CFA-001' => 'existing-uuid-1']);

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
        $this->assertSame(['CFA-001' => 'existing-uuid-1'], $capturedPayload['id_mappings']);
    }

    private function createController(
        RequestInterface $request,
        ParallelInsertService $insertService,
        ?IdMappingService $idMappingService = null,
        ?MigrationBatchService $batchService = null,
        ?ValidatorFactoryInterface $validatorFactory = null,
        mixed $responseMock = null
    ): CompanyLayoutFixedAccountMigrationController {
        $controller = new CompanyLayoutFixedAccountMigrationController();
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
