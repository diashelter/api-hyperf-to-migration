<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Service;

use App\Service\ParallelInsertService;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use HyperfTest\UnitTestCase;

#[CoversClass(ParallelInsertService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ParallelInsertServiceTest extends UnitTestCase
{
    public function testInsertSyncWrapsInsertInTransactionAndGeneratesMissingUuids(): void
    {
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $builder = Mockery::mock();
        $capturedRecords = [];

        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('table')->once()->with('companies')->andReturn($builder);
        $builder->shouldReceive('insert')
            ->once()
            ->with(Mockery::on(function (array $records) use (&$capturedRecords): bool {
                $capturedRecords = $records;

                return count($records) === 2;
            }));
        $db->shouldReceive('commit')->once();
        $db->shouldReceive('rollBack')->never();

        $result = (new ParallelInsertService())->insertSync('companies', [
            ['name' => 'ACME'],
            ['id' => 'existing-id', 'name' => 'Globex'],
        ]);

        $this->assertSame(['inserted' => 2, 'failed' => 0, 'errors' => []], $result);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $capturedRecords[0]['id']);
        $this->assertSame('existing-id', $capturedRecords[1]['id']);
    }

    public function testInsertSyncRollsBackWhenInsertFails(): void
    {
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $builder = Mockery::mock();

        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('table')->once()->with('companies')->andReturn($builder);
        $builder->shouldReceive('insert')->once()->andThrow(new RuntimeException('insert failed'));
        $db->shouldReceive('commit')->never();
        $db->shouldReceive('rollBack')->once();

        $result = (new ParallelInsertService())->insertSync('companies', [
            ['name' => 'ACME'],
            ['name' => 'Globex'],
        ]);

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame([['message' => 'insert failed']], $result['errors']);
    }

    public function testInsertBatchChunksRecordsAndAggregatesFailures(): void
    {
        $this->setEnvValue('MIGRATION_CHUNK_SIZE', '2');
        $this->setEnvValue('MIGRATION_MAX_COROUTINES', '3');

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $builder = Mockery::mock();
        $chunks = [];
        $callbacks = [];

        $db->shouldReceive('table')->twice()->with('companies')->andReturn($builder);
        $builder->shouldReceive('insert')
            ->twice()
            ->with(Mockery::on(function (array $records) use (&$chunks): bool {
                $chunks[] = $records;

                return true;
            }))
            ->andReturnUsing(function () use (&$chunks): bool {
                if (count($chunks) === 2) {
                    throw new RuntimeException('chunk failed');
                }

                return true;
            });

        $parallel = Mockery::mock('overload:Hyperf\Coroutine\Parallel');
        $parallel->shouldReceive('__construct')->once()->with(3);
        $parallel->shouldReceive('add')
            ->twice()
            ->andReturnUsing(function (callable $callback) use (&$callbacks): void {
                $callbacks[] = $callback;
            });
        $parallel->shouldReceive('wait')
            ->once()
            ->andReturnUsing(function () use (&$callbacks): array {
                return array_map(static fn (callable $callback) => $callback(), $callbacks);
            });

        $result = (new ParallelInsertService())->insertBatch('companies', [
            ['name' => 'one'],
            ['name' => 'two'],
            ['name' => 'three'],
        ]);

        $this->assertCount(2, $chunks);
        $this->assertCount(2, $chunks[0]);
        $this->assertCount(1, $chunks[1]);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $chunks[0][0]['id']);
        $this->assertSame(2, $result['inserted']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame([
            ['chunk_index' => 1, 'message' => 'chunk failed'],
        ], $result['errors']);
    }

    public function testUpsertBatchUsesConfiguredKeysAndAggregatesResults(): void
    {
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $builder = Mockery::mock();
        $callbacks = [];
        $upsertCalls = [];

        $db->shouldReceive('table')->twice()->with('companies')->andReturn($builder);
        $builder->shouldReceive('upsert')
            ->twice()
            ->with(Mockery::on(function (array $records) use (&$upsertCalls): bool {
                $upsertCalls[] = ['records' => $records];

                return true;
            }), ['legacy_id'], ['name'])
            ->andReturnTrue();

        $parallel = Mockery::mock('overload:Hyperf\Coroutine\Parallel');
        $parallel->shouldReceive('__construct')->once()->with(2);
        $parallel->shouldReceive('add')
            ->twice()
            ->andReturnUsing(function (callable $callback) use (&$callbacks): void {
                $callbacks[] = $callback;
            });
        $parallel->shouldReceive('wait')
            ->once()
            ->andReturnUsing(function () use (&$callbacks): array {
                return array_map(static fn (callable $callback) => $callback(), $callbacks);
            });

        $result = (new ParallelInsertService())->upsertBatch(
            'companies',
            [
                ['legacy_id' => 'l1', 'name' => 'one'],
                ['legacy_id' => 'l2', 'name' => 'two'],
                ['legacy_id' => 'l3', 'name' => 'three'],
            ],
            ['legacy_id'],
            ['name'],
            2,
            2
        );

        $this->assertCount(2, $upsertCalls);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $upsertCalls[0]['records'][0]['id']);
        $this->assertSame(['inserted' => 3, 'failed' => 0, 'errors' => []], $result);
    }
}
