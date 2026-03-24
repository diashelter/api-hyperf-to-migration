<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Controller;

use App\Controller\Migration\ImportRecordMigrationController;
use App\Model\MigrationBatch;
use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use Hyperf\HttpServer\Contract\RequestInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ImportRecordMigrationController::class)]
final class ImportRecordMigrationControllerTest extends UnitTestCase
{
    public function testMigrateReturnsValidationErrorWhenBatchExceedsLimit(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn(array_fill(0, 2001, ['name' => 'Record']));

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertBatch');

        $controller = $this->createController(
            $request,
            $insertService,
            $this->createStub(IdMappingService::class),
            $this->createStub(MigrationBatchService::class)
        );

        $this->assertSame(
            ['error' => 'Batch size exceeds maximum of 2000', 'code' => 422],
            $controller->migrate()
        );
    }

    public function testMigrateProcessesAsyncBatchAndMarksCompletedWithErrors(): void
    {
        $batch = [[
            'legacy_id' => 'legacy-record-1',
            'legacy_import_id' => 'legacy-import-1',
            'legacy_import_session_id' => 'legacy-session-1',
            'payload' => 'value',
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
        $idMappingService->expects($this->exactly(2))
            ->method('resolve')
            ->willReturnMap([
                ['imports', 'legacy-import-1', 'contract-1', 'import-uuid-1'],
                ['import_sessions', 'legacy-session-1', 'contract-1', 'session-uuid-1'],
            ]);

        $migrationBatch = new class extends MigrationBatch {
            public function __construct()
            {
            }
        };
        $migrationBatch->id = 'batch-1';

        $batchService = $this->createMock(MigrationBatchService::class);
        $batchService->expects($this->once())
            ->method('create')
            ->with('import_records', 1, 'contract-1')
            ->willReturn($migrationBatch);
        $batchService->expects($this->once())
            ->method('markProcessing')
            ->with('batch-1');

        $persistedRecords = [];
        $generatedId = null;
        $errors = [['chunk_index' => 0, 'message' => 'duplicate key']];

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertBatch')
            ->with(
                'import_records',
                $this->callback(function (array $records) use (&$persistedRecords): bool {
                    $persistedRecords = $records;

                    if (count($records) !== 1) {
                        return false;
                    }

                    $record = $records[0];

                    $this->assertArrayNotHasKey('legacy_id', $record);
                    $this->assertArrayNotHasKey('legacy_import_id', $record);
                    $this->assertArrayNotHasKey('legacy_import_session_id', $record);
                    $this->assertSame('import-uuid-1', $record['import_id']);
                    $this->assertSame('session-uuid-1', $record['import_session_id']);
                    $this->assertSame('value', $record['payload']);
                    $this->assertMatchesRegularExpression(self::UUID_PATTERN, $record['id']);
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['created_at']);
                    $this->assertSame($record['created_at'], $record['updated_at']);

                    return true;
                })
            )
            ->willReturn([
                'inserted' => 0,
                'failed' => 1,
                'errors' => $errors,
            ]);

        $idMappingService->expects($this->once())
            ->method('storeBatch')
            ->with(
                'import_records',
                $this->callback(function (array $mappings) use (&$generatedId): bool {
                    $generatedId = $mappings['legacy-record-1'] ?? null;

                    return is_string($generatedId)
                        && preg_match(self::UUID_PATTERN, $generatedId) === 1;
                }),
                'contract-1'
            );

        $batchService->expects($this->once())
            ->method('markCompleted')
            ->with('batch-1', 0, 1, $errors);

        $controller = $this->createController(
            $request,
            $insertService,
            $idMappingService,
            $batchService
        );

        $result = $controller->migrate();

        $this->assertSame('batch-1', $result['migration_batch_id']);
        $this->assertSame('import_records', $result['entity']);
        $this->assertSame(1, $result['total_received']);
        $this->assertSame('completed_with_errors', $result['status']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame($errors, $result['errors']);
        $this->assertSame(['legacy-record-1' => $generatedId], $result['id_mappings']);
        $this->assertSame('/api/v1/migration/status/batch-1', $result['status_url']);
        $this->assertSame($generatedId, $persistedRecords[0]['id']);
    }

    private function createController(
        RequestInterface $request,
        ParallelInsertService $insertService,
        IdMappingService $idMappingService,
        MigrationBatchService $batchService
    ): ImportRecordMigrationController {
        $controller = new ImportRecordMigrationController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'insertService', $insertService);
        $this->injectProperty($controller, 'idMappingService', $idMappingService);
        $this->injectProperty($controller, 'batchService', $batchService);

        return $controller;
    }
}
