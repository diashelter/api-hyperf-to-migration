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

namespace HyperfTest\Cases\Unit\Service;

use App\Service\MigrationAuditService;
use App\Service\ParallelInsertService;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @internal
 */
#[CoversClass(MigrationAuditService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MigrationAuditServiceTest extends UnitTestCase
{
    public function testOpenCreatesAuditLogWithReceivedStatus(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationAuditLog');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['request_id'] === 'request-1'
                    && preg_match(self::UUID_V7_PATTERN, $payload['id'] ?? '') === 1
                    && $payload['contract_id'] === 'contract-1'
                    && $payload['entity'] === 'contracts'
                    && $payload['status'] === 'received'
                    && $payload['total_received'] === 2
                    && is_string($payload['request_payload'] ?? null)
                    && is_string($payload['started_at'] ?? null);
            }));

        (new MigrationAuditService())->open(
            'request-1',
            'contract-1',
            'contracts',
            [['legacy_id' => 'LEG-1'], ['legacy_id' => 'LEG-2']],
            '127.0.0.1',
            'phpunit-agent'
        );

        $this->addToAssertionCount(1);
    }

    public function testCloseUpdatesTotalsAndStatus(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationAuditLog');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->once()->with('request_id', 'request-1')->andReturnSelf();
        $query->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['total_inserted'] === 2
                    && $payload['total_failed'] === 1
                    && $payload['total_skipped'] === 1
                    && $payload['status'] === 'completed_with_errors'
                    && is_string($payload['response_payload'] ?? null)
                    && is_int($payload['processing_time_ms'] ?? null)
                    && is_string($payload['completed_at'] ?? null);
            }));

        (new MigrationAuditService())->close('request-1', [
            'inserted' => 2,
            'failed' => 1,
            'skipped' => 1,
            'errors' => [['message' => 'insert failed']],
            'id_mappings' => ['LEG-1' => 'uuid-1'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testLogRecordsPersistsBatchUsingDefaultConnection(): void
    {
        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertSync')
            ->with(
                'migration_record_logs',
                $this->callback(function (array $records): bool {
                    return count($records) === 2
                        && $records[0]['request_id'] === 'request-1'
                        && $records[0]['contract_id'] === 'contract-1'
                        && $records[0]['entity'] === 'contracts'
                        && preg_match(self::UUID_V7_PATTERN, $records[0]['id'] ?? '') === 1
                        && is_string($records[0]['created_at'] ?? null);
                }),
                'default'
            )
            ->willReturn(['inserted' => 2, 'failed' => 0, 'errors' => []]);

        $service = new MigrationAuditService();
        $this->injectProperty($service, 'insertService', $insertService);

        $service->logRecords('request-1', 'contract-1', 'contracts', [
            ['legacy_id' => 'LEG-1', 'new_id' => 'uuid-1', 'status' => 'inserted'],
            ['legacy_id' => 'LEG-2', 'new_id' => null, 'status' => 'failed', 'error_message' => 'boom'],
        ]);
    }

    public function testShouldLogRecordsHonorsEnvironmentFlags(): void
    {
        $service = new MigrationAuditService();

        $this->setEnvValue('MIGRATION_LOG_RECORDS', 'false');
        $this->assertFalse($service->shouldLogRecords('contracts'));

        $this->setEnvValue('MIGRATION_LOG_RECORDS', 'true');
        $this->setEnvValue('MIGRATION_SKIP_LOG_ENTITIES', 'import_records,rules');
        $this->assertFalse($service->shouldLogRecords('import_records'));
        $this->assertTrue($service->shouldLogRecords('contracts'));
    }
}
