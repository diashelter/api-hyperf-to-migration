<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Controller;

use App\Controller\Migration\CompanyMigrationController;
use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use Hyperf\HttpServer\Contract\RequestInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CompanyMigrationController::class)]
final class CompanyMigrationControllerTest extends UnitTestCase
{
    public function testMigrateReturnsValidationErrorWhenBatchIsEmpty(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn([]);

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController(
            $request,
            $insertService,
            $this->createStub(IdMappingService::class),
            $this->createStub(MigrationBatchService::class)
        );

        $this->assertSame(
            ['error' => 'Empty batch', 'code' => 422],
            $controller->migrate()
        );
    }

    public function testMigrateReturnsValidationErrorWhenBatchExceedsLimit(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn(array_fill(0, 101, ['name' => 'Company']));

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController(
            $request,
            $insertService,
            $this->createStub(IdMappingService::class),
            $this->createStub(MigrationBatchService::class)
        );

        $this->assertSame(
            ['error' => 'Batch size exceeds maximum of 100', 'code' => 422],
            $controller->migrate()
        );
    }

    public function testMigrateTransformsAndPersistsSyncBatch(): void
    {
        $batch = [[
            'legacy_id' => 'legacy-company-1',
            'legacy_contract_id' => 'legacy-contract-1',
            'legacy_plan_id' => 'legacy-plan-1',
            'legacy_rules_sharing_id' => 'legacy-rules-sharing-1',
            'name' => 'ACME',
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->exactly(2))
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
        $idMappingService->expects($this->exactly(3))
            ->method('resolve')
            ->willReturnMap([
                ['contracts', 'legacy-contract-1', 'contract-1', 'contract-uuid-1'],
                ['plans', 'legacy-plan-1', 'contract-1', 'plan-uuid-1'],
                ['rules_sharings', 'legacy-rules-sharing-1', 'contract-1', 'rules-sharing-uuid-1'],
            ]);

        $persistedRecords = [];
        $generatedId = null;

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertSync')
            ->with(
                'companies',
                $this->callback(function (array $records) use (&$persistedRecords): bool {
                    $persistedRecords = $records;

                    if (count($records) !== 1) {
                        return false;
                    }

                    $record = $records[0];

                    $this->assertArrayNotHasKey('legacy_id', $record);
                    $this->assertArrayNotHasKey('legacy_contract_id', $record);
                    $this->assertArrayNotHasKey('legacy_plan_id', $record);
                    $this->assertArrayNotHasKey('legacy_rules_sharing_id', $record);
                    $this->assertSame('ACME', $record['name']);
                    $this->assertSame('contract-uuid-1', $record['contract_id']);
                    $this->assertSame('plan-uuid-1', $record['plan_id']);
                    $this->assertSame('rules-sharing-uuid-1', $record['rules_sharing_id']);
                    $this->assertMatchesRegularExpression(self::UUID_PATTERN, $record['id']);
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['created_at']);
                    $this->assertSame($record['created_at'], $record['updated_at']);

                    return true;
                })
            )
            ->willReturn(['inserted' => 1, 'failed' => 0, 'errors' => []]);

        $idMappingService->expects($this->once())
            ->method('storeBatch')
            ->with(
                'companies',
                $this->callback(function (array $mappings) use (&$generatedId): bool {
                    $generatedId = $mappings['legacy-company-1'] ?? null;

                    return is_string($generatedId)
                        && preg_match(self::UUID_PATTERN, $generatedId) === 1;
                }),
                'contract-1'
            );

        $controller = $this->createController(
            $request,
            $insertService,
            $idMappingService,
            $this->createStub(MigrationBatchService::class)
        );

        $result = $controller->migrate();

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(['legacy-company-1' => $generatedId], $result['id_mappings']);
        $this->assertSame($generatedId, $persistedRecords[0]['id']);
    }

    private function createController(
        RequestInterface $request,
        ParallelInsertService $insertService,
        IdMappingService $idMappingService,
        MigrationBatchService $batchService
    ): CompanyMigrationController {
        $controller = new CompanyMigrationController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'insertService', $insertService);
        $this->injectProperty($controller, 'idMappingService', $idMappingService);
        $this->injectProperty($controller, 'batchService', $batchService);

        return $controller;
    }
}
