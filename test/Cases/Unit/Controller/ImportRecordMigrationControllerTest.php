<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Controller;

use App\Controller\Migration\ImportRecordMigrationController;
use App\Model\MigrationBatch;
use App\Service\IdMappingService;
use App\Service\MigrationAuditService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Ramsey\Uuid\Uuid;

#[CoversClass(ImportRecordMigrationController::class)]
final class ImportRecordMigrationControllerTest extends UnitTestCase
{
    public function testMigrateReturnsValidationErrorWhenBatchExceedsLimit(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->exactly(2))
            ->method('input')
            ->willReturnMap([
                ['batch', [], array_fill(0, 2001, ['name' => 'Record'])],
                ['finalize', false, false],
            ]);

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
        $request->expects($this->exactly(3))
            ->method('input')
            ->willReturnMap([
                ['batch', [], $batch],
                ['finalize', false, false],
            ]);
        $request->expects($this->exactly(2))
            ->method('header')
            ->willReturnMap([
                ['X-Contract-Id', '', 'header-contract'],
                ['user-agent', '', 'phpunit-agent'],
            ]);
        $request->expects($this->once())
            ->method('getAttribute')
            ->with('contract_id', 'header-contract')
            ->willReturn('contract-1');
        $request->expects($this->once())
            ->method('getServerParams')
            ->willReturn(['remote_addr' => '127.0.0.1']);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(false);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->once())
            ->method('make')
            ->willReturn($validator);

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->once())
            ->method('resolveMany')
            ->with('import_records', ['legacy-record-1'], 'contract-1')
            ->willReturn([]);
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
                    $this->assertTrue(Uuid::isValid($record['id']));
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['created_at']);
                    $this->assertSame($record['created_at'], $record['updated_at']);

                    return true;
                }),
                0,
                0,
                'conciliador_web'
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

                    return is_string($generatedId) && Uuid::isValid($generatedId);
                }),
                'contract-1',
                'batch-1'
            );

        $batchService->expects($this->once())
            ->method('markCompleted')
            ->with('batch-1', 0, 1, $errors);

        $capturedRequestId = null;
        $auditService = $this->createMock(MigrationAuditService::class);
        $auditService->expects($this->once())
            ->method('open')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    $capturedRequestId = $requestId;

                    return preg_match(self::UUID_PATTERN, $requestId) === 1;
                }),
                'contract-1',
                'import_records',
                $batch,
                '127.0.0.1',
                'phpunit-agent'
            );
        $auditService->expects($this->once())
            ->method('close')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    return $requestId === $capturedRequestId;
                }),
                $this->callback(function (array $result) use (&$generatedId, $errors): bool {
                    $this->assertSame(0, $result['inserted']);
                    $this->assertSame(0, $result['skipped']);
                    $this->assertSame(1, $result['failed']);
                    $this->assertSame($errors, $result['errors']);
                    $this->assertSame(['legacy-record-1' => $generatedId], $result['id_mappings']);

                    return true;
                }),
                'batch-1'
            );
        $auditService->expects($this->once())
            ->method('shouldLogRecords')
            ->with('import_records')
            ->willReturn(true);
        $auditService->expects($this->once())
            ->method('logRecords')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    return $requestId === $capturedRequestId;
                }),
                'contract-1',
                'import_records',
                $this->callback(function (array $recordLogs): bool {
                    $this->assertCount(1, $recordLogs);
                    $this->assertSame('failed', $recordLogs[0]['status']);
                    $this->assertSame('legacy-record-1', $recordLogs[0]['legacy_id']);
                    $this->assertNull($recordLogs[0]['new_id']);

                    return true;
                })
            );

        $controller = $this->createController(
            $request,
            $insertService,
            $idMappingService,
            $batchService,
            $validatorFactory,
            $auditService
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

    public function testMigrateSkipsExistingMappingsInAsyncFlow(): void
    {
        $batch = [
            [
                'legacy_id' => 'legacy-existing',
                'date' => '2024-01-10',
                'history' => 'Registro existente',
                'legacy_import_id' => 'legacy-import-1',
                'legacy_import_session_id' => 'legacy-session-1',
            ],
            [
                'legacy_id' => 'legacy-new',
                'date' => '2024-01-11',
                'history' => 'Registro novo',
                'legacy_import_id' => 'legacy-import-1',
                'legacy_import_session_id' => 'legacy-session-1',
            ],
        ];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->exactly(3))
            ->method('input')
            ->willReturnMap([
                ['batch', [], $batch],
                ['finalize', false, false],
            ]);
        $request->expects($this->exactly(2))
            ->method('header')
            ->willReturnMap([
                ['X-Contract-Id', '', 'header-contract'],
                ['user-agent', '', 'phpunit-agent'],
            ]);
        $request->expects($this->once())
            ->method('getAttribute')
            ->with('contract_id', 'header-contract')
            ->willReturn('contract-1');
        $request->expects($this->once())
            ->method('getServerParams')
            ->willReturn(['remote_addr' => '127.0.0.1']);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(false);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->exactly(2))
            ->method('make')
            ->willReturn($validator);

        $migrationBatch = new class extends MigrationBatch {
            public function __construct()
            {
            }
        };
        $migrationBatch->id = 'batch-2';

        $batchService = $this->createMock(MigrationBatchService::class);
        $batchService->expects($this->once())
            ->method('create')
            ->with('import_records', 1, 'contract-1')
            ->willReturn($migrationBatch);
        $batchService->expects($this->once())
            ->method('markProcessing')
            ->with('batch-2');
        $batchService->expects($this->once())
            ->method('markCompleted')
            ->with('batch-2', 1, 0, null);

        $generatedId = null;
        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->once())
            ->method('resolveMany')
            ->with('import_records', ['legacy-existing', 'legacy-new'], 'contract-1')
            ->willReturn(['legacy-existing' => 'existing-uuid-1']);
        $idMappingService->expects($this->exactly(2))
            ->method('resolve')
            ->willReturnMap([
                ['imports', 'legacy-import-1', 'contract-1', 'import-uuid-1'],
                ['import_sessions', 'legacy-session-1', 'contract-1', 'session-uuid-1'],
            ]);
        $idMappingService->expects($this->once())
            ->method('storeBatch')
            ->with(
                'import_records',
                $this->callback(function (array $mappings) use (&$generatedId): bool {
                    $generatedId = $mappings['legacy-new'] ?? null;

                    return is_string($generatedId) && Uuid::isValid($generatedId);
                }),
                'contract-1',
                'batch-2'
            );

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertBatch')
            ->with(
                'import_records',
                $this->callback(function (array $records) use (&$generatedId): bool {
                    $this->assertCount(1, $records);
                    $this->assertSame('import-uuid-1', $records[0]['import_id']);
                    $this->assertSame('session-uuid-1', $records[0]['import_session_id']);
                    $generatedId = $records[0]['id'];

                    return true;
                }),
                0,
                0,
                'conciliador_web'
            )
            ->willReturn([
                'inserted' => 1,
                'failed' => 0,
                'errors' => [],
            ]);

        $capturedRequestId = null;
        $auditService = $this->createMock(MigrationAuditService::class);
        $auditService->expects($this->once())
            ->method('open')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    $capturedRequestId = $requestId;

                    return preg_match(self::UUID_PATTERN, $requestId) === 1;
                }),
                'contract-1',
                'import_records',
                $batch,
                '127.0.0.1',
                'phpunit-agent'
            );
        $auditService->expects($this->once())
            ->method('close')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    return $requestId === $capturedRequestId;
                }),
                $this->callback(function (array $result) use (&$generatedId): bool {
                    $this->assertSame(1, $result['inserted']);
                    $this->assertSame(1, $result['skipped']);
                    $this->assertSame(0, $result['failed']);
                    $this->assertSame([
                        'legacy-existing' => 'existing-uuid-1',
                        'legacy-new' => $generatedId,
                    ], $result['id_mappings']);

                    return true;
                }),
                'batch-2'
            );
        $auditService->expects($this->once())
            ->method('shouldLogRecords')
            ->with('import_records')
            ->willReturn(true);
        $auditService->expects($this->once())
            ->method('logRecords')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    return $requestId === $capturedRequestId;
                }),
                'contract-1',
                'import_records',
                $this->callback(function (array $recordLogs) use (&$generatedId): bool {
                    $this->assertCount(2, $recordLogs);
                    $this->assertSame('skipped_duplicate', $recordLogs[0]['status']);
                    $this->assertSame('legacy-existing', $recordLogs[0]['legacy_id']);
                    $this->assertSame('existing-uuid-1', $recordLogs[0]['new_id']);
                    $this->assertSame('inserted', $recordLogs[1]['status']);
                    $this->assertSame('legacy-new', $recordLogs[1]['legacy_id']);
                    $this->assertSame($generatedId, $recordLogs[1]['new_id']);

                    return true;
                })
            );

        $controller = $this->createController(
            $request,
            $insertService,
            $idMappingService,
            $batchService,
            $validatorFactory,
            $auditService
        );

        $result = $controller->migrate();

        $this->assertSame('batch-2', $result['migration_batch_id']);
        $this->assertSame(2, $result['total_received']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame([
            'legacy-existing' => 'existing-uuid-1',
            'legacy-new' => $generatedId,
        ], $result['id_mappings']);
    }

    private function createController(
        RequestInterface $request,
        ParallelInsertService $insertService,
        IdMappingService $idMappingService,
        MigrationBatchService $batchService,
        ?ValidatorFactoryInterface $validatorFactory = null,
        ?MigrationAuditService $auditService = null
    ): ImportRecordMigrationController {
        $controller = new ImportRecordMigrationController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'insertService', $insertService);
        $this->injectProperty($controller, 'idMappingService', $idMappingService);
        $this->injectProperty($controller, 'batchService', $batchService);
        $this->injectProperty($controller, 'validatorFactory', $validatorFactory ?? $this->createStub(ValidatorFactoryInterface::class));
        $this->injectProperty($controller, 'auditService', $auditService ?? $this->createStub(MigrationAuditService::class));

        return $controller;
    }
}
