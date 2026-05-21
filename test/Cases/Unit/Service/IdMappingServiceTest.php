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

use App\Service\IdMappingService;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(IdMappingService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IdMappingServiceTest extends UnitTestCase
{
    public function testStoreUpdatesOrCreatesMappingWithGeneratedUuid(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationIdMapping');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('updateOrCreate')
            ->once()
            ->with(
                [
                    'entity' => 'companies',
                    'legacy_id' => 'legacy-1',
                    'contract_id' => 'contract-1',
                ],
                Mockery::on(function (array $payload): bool {
                    return is_string($payload['id'] ?? null)
                        && preg_match(self::UUID_V7_PATTERN, $payload['id']) === 1
                        && $payload['new_id'] === 'new-1'
                        && $payload['migration_batch_id'] === 'batch-1';
                })
            );

        (new IdMappingService())->store('companies', 'legacy-1', 'new-1', 'contract-1', 'batch-1');
        $this->addToAssertionCount(1);
    }

    public function testStoreBatchUpsertsInChunksOfFiveHundredRecords(): void
    {
        $this->setEnvValue('MIGRATION_MAPPING_CHUNK_SIZE', '500');
        $this->setEnvValue('MIGRATION_MAPPING_COROUTINES', '1');

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $builder = Mockery::mock();
        $capturedChunks = [];

        $db->shouldReceive('table')
            ->twice()
            ->with('migration_id_mappings')
            ->andReturn($builder);

        $builder->shouldReceive('upsert')
            ->twice()
            ->withArgs(function (array $chunk, array $uniqueKeys, array $updateColumns) use (&$capturedChunks): bool {
                $capturedChunks[] = $chunk;

                return $uniqueKeys === ['entity', 'legacy_id', 'contract_id']
                    && $updateColumns === ['new_id', 'migration_batch_id'];
            });

        $mappings = [];
        for ($index = 1; $index <= 501; ++$index) {
            $mappings["legacy-{$index}"] = "new-{$index}";
        }

        (new IdMappingService())->storeBatch('companies', $mappings, 'contract-1', 'batch-1');

        $this->assertCount(2, $capturedChunks);
        $this->assertCount(500, $capturedChunks[0]);
        $this->assertCount(1, $capturedChunks[1]);
        $this->assertSame('companies', $capturedChunks[0][0]['entity']);
        $this->assertSame('legacy-1', $capturedChunks[0][0]['legacy_id']);
        $this->assertSame('new-1', $capturedChunks[0][0]['new_id']);
        $this->assertSame('contract-1', $capturedChunks[0][0]['contract_id']);
        $this->assertSame('batch-1', $capturedChunks[0][0]['migration_batch_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $capturedChunks[0][0]['created_at']);
        $this->assertMatchesRegularExpression(self::UUID_V7_PATTERN, $capturedChunks[0][0]['id']);
        $this->assertSame('legacy-501', $capturedChunks[1][0]['legacy_id']);
    }

    public function testStoreBatchSkipsDatabaseWhenMappingsAreEmpty(): void
    {
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $db->shouldReceive('table')->never();

        (new IdMappingService())->storeBatch('companies', [], 'contract-1');

        $this->addToAssertionCount(1);
    }

    public function testResolveReturnsTheMappedIdWhenFound(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationIdMapping');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->times(3)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) ['new_id' => 'new-1']);

        $this->assertSame(
            'new-1',
            (new IdMappingService())->resolve('companies', 'legacy-1', 'contract-1')
        );
    }

    public function testResolveReturnsNullWhenMappingDoesNotExist(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationIdMapping');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->times(3)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn(null);

        $this->assertNull(
            (new IdMappingService())->resolve('companies', 'legacy-1', 'contract-1')
        );
    }

    public function testResolveManyReturnsMappingsIndexedByLegacyId(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationIdMapping');
        $query = Mockery::mock();
        $collection = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->twice()->andReturnSelf();
        $query->shouldReceive('whereIn')->once()->with('legacy_id', ['legacy-1', 'legacy-2'])->andReturnSelf();
        $query->shouldReceive('pluck')->once()->with('new_id', 'legacy_id')->andReturn($collection);
        $collection->shouldReceive('toArray')->once()->andReturn(['legacy-1' => 'new-1']);

        $this->assertSame(
            ['legacy-1' => 'new-1'],
            (new IdMappingService())->resolveMany('companies', ['legacy-1', 'legacy-2'], 'contract-1')
        );
    }

    public function testResolveOrFailReturnsResolvedId(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationIdMapping');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->times(3)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) ['new_id' => 'new-1']);

        $this->assertSame(
            'new-1',
            (new IdMappingService())->resolveOrFail('companies', 'legacy-1', 'contract-1')
        );
    }

    public function testResolveOrFailThrowsWhenMappingIsMissing(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationIdMapping');
        $query = Mockery::mock();

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->times(3)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("ID mapping not found for entity 'companies' with legacy_id 'legacy-1'");

        (new IdMappingService())->resolveOrFail('companies', 'legacy-1', 'contract-1');
    }
}
