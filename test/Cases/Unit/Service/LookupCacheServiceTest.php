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

use App\Service\LookupCacheService;
use App\Service\LookupSeedResult;
use HyperfTest\UnitTestCase;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use stdClass;

/**
 * seed(): caracterização do comportamento anterior às contagens (T1), que segue igual.
 * seedWithChanges(): contagens de inseridos/atualizados/removidos (T3).
 *
 * @internal
 */
#[CoversClass(LookupCacheService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LookupCacheServiceTest extends UnitTestCase
{
    private const ENTITY = 'layouts_admin';

    private const ENVIRONMENT = 'testing';

    private const UNIQUE_BY = ['entity', 'external_id', 'environment'];

    private const UPDATE_COLUMNS = ['label', 'payload', 'updated_at'];

    /**
     * Lotes recebidos pelo upsert do lookup_cache, na ordem das chamadas.
     *
     * @var list<list<array<string, mixed>>>
     */
    private array $upsertedChunks = [];

    /**
     * Ids passados ao whereNotIn do delete (null = delete não chamado).
     *
     * @var null|list<string>
     */
    private ?array $keptIds = null;

    /**
     * Expressão recebida pelo selectRaw da origem (null = select não chamado).
     */
    private ?string $selectExpression = null;

    /**
     * Quantas vezes o estado atual do lookup_cache foi lido (pluck).
     */
    private int $cacheReads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvValue('APP_ENV', self::ENVIRONMENT);
    }

    public function testSeedThrowsForUnsupportedEntity(): void
    {
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $db->shouldNotReceive('connection', 'table');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Entity 'foo' is not supported for lookup caching.");

        (new LookupCacheService())->seed('foo');
    }

    public function testSeedReturnsZeroAndDoesNotDeleteWhenSourceIsEmpty(): void
    {
        $this->mockDb([]);

        $this->assertSame(0, (new LookupCacheService())->seed(self::ENTITY));
        $this->assertNull($this->keptIds);
        $this->assertSame([], $this->upsertedChunks);
    }

    public function testSeedLayoutsAdminSelectsIdAndCodeFromConciliadorWeb(): void
    {
        $this->mockDb([$this->row('uuid-1', '10')]);

        (new LookupCacheService())->seed(self::ENTITY);

        $this->assertSame('id as external_id, code as label', $this->selectExpression);
    }

    public function testSeedDeletesCachedIdsMissingFromSourceForEntityAndEnvironment(): void
    {
        $cacheBuilder = Mockery::mock();
        $this->mockDb([$this->row('uuid-1', '10'), $this->row('uuid-2', '20')], $cacheBuilder);

        (new LookupCacheService())->seed(self::ENTITY);

        $cacheBuilder->shouldHaveReceived('where')->with('entity', self::ENTITY);
        $cacheBuilder->shouldHaveReceived('where')->with('environment', self::ENVIRONMENT);
        $cacheBuilder->shouldHaveReceived('delete')->once();
        $this->assertSame(['uuid-1', 'uuid-2'], $this->keptIds);
    }

    public function testSeedUpsertsInChunksOf500WithUniqueKeyAndUpdateColumns(): void
    {
        $rows = [];
        for ($i = 1; $i <= 501; ++$i) {
            $rows[] = $this->row($i, (string) ($i * 10));
        }
        $this->mockDb($rows);

        (new LookupCacheService())->seed(self::ENTITY);

        $this->assertCount(2, $this->upsertedChunks);
        $this->assertCount(500, $this->upsertedChunks[0]);
        $this->assertCount(1, $this->upsertedChunks[1]);

        $first = $this->upsertedChunks[0][0];
        $this->assertSame('1', $first['external_id']);
        $this->assertSame(self::ENTITY, $first['entity']);
        $this->assertSame('10', $first['label']);
        $this->assertNull($first['payload']);
        $this->assertSame(self::ENVIRONMENT, $first['environment']);
        $this->assertSame($first['created_at'], $first['updated_at']);
        $this->assertSame('501', $this->upsertedChunks[1][0]['external_id']);
    }

    public function testSeedReturnsNumberOfSourceRows(): void
    {
        $this->mockDb([$this->row('uuid-1', '10'), $this->row('uuid-2', '20'), $this->row('uuid-3', '30')]);

        $this->assertSame(3, (new LookupCacheService())->seed(self::ENTITY));
    }

    public function testSeedWithChangesCountsInsertedUpdatedAndRemoved(): void
    {
        $this->mockDb(
            [$this->row('uuid-1', '10'), $this->row('uuid-2', '20'), $this->row('uuid-3', '30')],
            cachedLabels: ['uuid-1' => '10', 'uuid-2' => '25', 'uuid-9' => '90'],
            removed: 1,
        );

        $result = (new LookupCacheService())->seedWithChanges(self::ENTITY);

        $this->assertSame(self::ENTITY, $result->entity);
        $this->assertSame(3, $result->total);
        $this->assertSame(1, $result->inserted);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->removed);
        $this->assertTrue($result->hasChanges());
        $this->assertSame(1, $this->cacheReads);
        $this->assertCount(1, $this->upsertedChunks);
    }

    public function testSeedWithChangesReportsNoChangesWhenCacheMatchesSource(): void
    {
        // Código inteiro na origem × label texto no cache, e id numérico como chave do pluck.
        $this->mockDb(
            [$this->row(7, 10), $this->row('uuid-2', null)],
            cachedLabels: [7 => '10', 'uuid-2' => null],
        );

        $result = (new LookupCacheService())->seedWithChanges(self::ENTITY);

        $this->assertSame(2, $result->total);
        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->removed);
        $this->assertFalse($result->hasChanges());
    }

    public function testSeedWithChangesReturnsZerosAndSkipsCacheWhenSourceIsEmpty(): void
    {
        $this->mockDb([], cachedLabels: ['uuid-1' => '10']);

        $result = (new LookupCacheService())->seedWithChanges(self::ENTITY);

        $this->assertEquals(new LookupSeedResult(self::ENTITY, 0, 0, 0, 0), $result);
        $this->assertFalse($result->hasChanges());
        $this->assertSame(0, $this->cacheReads);
        $this->assertNull($this->keptIds);
        $this->assertSame([], $this->upsertedChunks);
    }

    private function row(int|string $id, int|string|null $label): stdClass
    {
        $row = new stdClass();
        $row->external_id = $id;
        $row->label = $label;

        return $row;
    }

    /**
     * Simula o Db: origem conciliador_web.layouts_admin com $rows e o lookup_cache local.
     *
     * @param list<stdClass> $rows
     * @param array<int|string, null|string> $cachedLabels estado atual do cache [external_id => label]
     * @param int $removed retorno do delete() dos ids que sumiram da origem
     */
    private function mockDb(
        array $rows,
        ?MockInterface $cacheBuilder = null,
        array $cachedLabels = [],
        int $removed = 0,
    ): void {
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();
        $sourceBuilder = Mockery::mock();
        $collection = Mockery::mock();
        $cacheBuilder ??= Mockery::mock();

        $db->shouldReceive('connection')->with('conciliador_web')->andReturn($connection);
        $connection->shouldReceive('table')->with('layouts_admin')->andReturn($sourceBuilder);
        $sourceBuilder->shouldReceive('selectRaw')
            ->once()
            ->andReturnUsing(function (string $expression) use ($sourceBuilder): MockInterface {
                $this->selectExpression = $expression;

                return $sourceBuilder;
            });
        $sourceBuilder->shouldReceive('get')->once()->andReturn($collection);
        $collection->shouldReceive('toArray')->once()->andReturn($rows);

        $db->shouldReceive('table')->with('lookup_cache')->andReturn($cacheBuilder);
        $cacheBuilder->shouldReceive('where')->andReturnSelf();
        $cacheBuilder->shouldReceive('pluck')
            ->with('label', 'external_id')
            ->andReturnUsing(function () use ($cachedLabels): MockInterface {
                ++$this->cacheReads;
                $cached = Mockery::mock();
                $cached->shouldReceive('toArray')->once()->andReturn($cachedLabels);

                return $cached;
            });
        $cacheBuilder->shouldReceive('whereNotIn')
            ->with('external_id', Mockery::type('array'))
            ->andReturnUsing(function (string $column, array $ids) use ($cacheBuilder): MockInterface {
                $this->keptIds = $ids;

                return $cacheBuilder;
            });
        $cacheBuilder->shouldReceive('delete')->andReturn($removed);
        $cacheBuilder->shouldReceive('upsert')
            ->with(Mockery::type('array'), self::UNIQUE_BY, self::UPDATE_COLUMNS)
            ->andReturnUsing(function (array $chunk): int {
                $this->upsertedChunks[] = $chunk;

                return count($chunk);
            });
    }
}
