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

use App\Model\MigrationBatch;
use App\Service\MigrationBatchService;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @internal
 */
#[CoversClass(MigrationBatchService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MigrationBatchServiceTest extends UnitTestCase
{
    public function testCreateBuildsAQueuedBatchPayload(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();
        $createdBatch = new class extends MigrationBatch {
            public function __construct()
            {
            }
        };
        $createdBatch->id = 'batch-1';

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return is_string($payload['id'] ?? null)
                    && preg_match(self::UUID_V7_PATTERN, $payload['id']) === 1
                    && $payload['contract_id'] === 'contract-1'
                    && $payload['entity'] === 'companies'
                    && $payload['status'] === 'queued'
                    && $payload['total_records'] === 10
                    && $payload['processed_records'] === 0
                    && $payload['failed_records'] === 0
                    && $payload['started_at'] !== null;
            }))
            ->andReturn($createdBatch);

        $this->assertSame(
            $createdBatch,
            (new MigrationBatchService())->create('companies', 10, 'contract-1')
        );
    }

    public function testMarkProcessingUpdatesStatusAndStartedAt(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->once()->with('id', 'batch-1')->andReturnSelf();
        $query->shouldReceive('update')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => $payload['status'] === 'processing' && $payload['started_at'] !== null));

        (new MigrationBatchService())->markProcessing('batch-1');
        $this->addToAssertionCount(1);
    }

    public function testUpdateProgressPersistsProcessedAndFailedCounters(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->once()->with('id', 'batch-1')->andReturnSelf();
        $query->shouldReceive('update')
            ->once()
            ->with([
                'processed_records' => 8,
                'failed_records' => 2,
            ]);

        (new MigrationBatchService())->updateProgress('batch-1', 8, 2);
        $this->addToAssertionCount(1);
    }

    public function testMarkCompletedSetsCompletedStatusWhenThereAreNoFailures(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->once()->with('id', 'batch-1')->andReturnSelf();
        $query->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['status'] === 'completed'
                    && $payload['processed_records'] === 10
                    && $payload['failed_records'] === 0
                    && $payload['error_details'] === null
                    && $payload['completed_at'] !== null;
            }));

        (new MigrationBatchService())->markCompleted('batch-1', 10, 0);
        $this->addToAssertionCount(1);
    }

    public function testMarkCompletedStoresErrorsWhenFailuresExist(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();
        $errors = [['chunk_index' => 0, 'message' => 'duplicate key']];

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->once()->with('id', 'batch-1')->andReturnSelf();
        $query->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($errors): bool {
                return $payload['status'] === 'completed_with_errors'
                    && $payload['processed_records'] === 7
                    && $payload['failed_records'] === 3
                    && $payload['error_details'] === json_encode($errors)
                    && $payload['completed_at'] !== null;
            }));

        (new MigrationBatchService())->markCompleted('batch-1', 7, 3, $errors);
        $this->addToAssertionCount(1);
    }

    public function testMarkFailedPersistsFailureStatusAndErrorMessage(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->once()->with('id', 'batch-1')->andReturnSelf();
        $query->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['status'] === 'failed'
                    && $payload['error_details'] === json_encode(['message' => 'boom'])
                    && $payload['completed_at'] !== null;
            }));

        (new MigrationBatchService())->markFailed('batch-1', 'boom');
        $this->addToAssertionCount(1);
    }

    public function testGetStatusReturnsNullWhenBatchDoesNotExist(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('find')->once()->with('batch-1')->andReturn(null);

        $this->assertNull((new MigrationBatchService())->getStatus('batch-1'));
    }

    public function testGetStatusBuildsTheResponsePayload(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();
        $batch = (object) [
            'id' => 'batch-1',
            'entity' => 'companies',
            'status' => 'completed_with_errors',
            'total_records' => 10,
            'processed_records' => 7,
            'failed_records' => 3,
            'error_details' => json_encode([['message' => 'duplicate key']]),
            'started_at' => '2026-03-23 10:00:00',
            'completed_at' => '2026-03-23 10:05:00',
        ];

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('find')->once()->with('batch-1')->andReturn($batch);

        $this->assertSame(
            [
                'migration_batch_id' => 'batch-1',
                'entity' => 'companies',
                'status' => 'completed_with_errors',
                'total_records' => 10,
                'processed_records' => 7,
                'failed_records' => 3,
                'progress_percentage' => 70.0,
                'errors' => [['message' => 'duplicate key']],
                'started_at' => '2026-03-23 10:00:00',
                'completed_at' => '2026-03-23 10:05:00',
            ],
            (new MigrationBatchService())->getStatus('batch-1')
        );
    }

    public function testGetStatusReturnsZeroProgressWhenThereAreNoRecords(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();
        $batch = (object) [
            'id' => 'batch-1',
            'entity' => 'companies',
            'status' => 'queued',
            'total_records' => 0,
            'processed_records' => 0,
            'failed_records' => 0,
            'error_details' => null,
            'started_at' => null,
            'completed_at' => null,
        ];

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('find')->once()->with('batch-1')->andReturn($batch);

        $status = (new MigrationBatchService())->getStatus('batch-1');

        $this->assertSame(0, $status['progress_percentage']);
        $this->assertSame([], $status['errors']);
    }

    public function testListByContractReturnsBatchesOrderedByCreationDate(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationBatch');
        $query = Mockery::mock();
        $collection = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->once()->with('contract_id', 'contract-1')->andReturnSelf();
        $query->shouldReceive('orderByDesc')->once()->with('created_at')->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn($collection);
        $collection->shouldReceive('toArray')->once()->andReturn([['id' => 'batch-2'], ['id' => 'batch-1']]);

        $this->assertSame(
            [['id' => 'batch-2'], ['id' => 'batch-1']],
            (new MigrationBatchService())->listByContract('contract-1')
        );
    }
}
