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

use App\Service\EntityMigrator;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(EntityMigrator::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EntityMigratorTest extends UnitTestCase
{
    public function testFilterExistingContractUserRecordsSkipsExistingAndRepeatedPairs(): void
    {
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();
        $builder = Mockery::mock();

        $db->shouldReceive('connection')
            ->once()
            ->with('conciliador_web')
            ->andReturn($connection);

        $connection->shouldReceive('table')
            ->once()
            ->with('contract_user')
            ->andReturn($builder);

        $builder->shouldReceive('select')
            ->once()
            ->with(['user_id', 'contract_id'])
            ->andReturnSelf();

        $builder->shouldReceive('whereIn')
            ->once()
            ->with('user_id', ['user-1', 'user-2', 'user-3'])
            ->andReturnSelf();

        $builder->shouldReceive('whereIn')
            ->once()
            ->with('contract_id', ['contract-1', 'contract-2'])
            ->andReturnSelf();

        $builder->shouldReceive('get')
            ->once()
            ->andReturn([
                (object) ['user_id' => 'user-2', 'contract_id' => 'contract-1'],
            ]);

        $records = [
            ['user_id' => 'user-1', 'contract_id' => 'contract-1', 'role_id' => 'role-user', 'contract_admin' => false],
            ['user_id' => 'user-1', 'contract_id' => 'contract-1', 'role_id' => 'role-user', 'contract_admin' => false],
            ['user_id' => 'user-2', 'contract_id' => 'contract-1', 'role_id' => 'role-owner', 'contract_admin' => true],
            ['user_id' => 'user-3', 'contract_id' => 'contract-2', 'role_id' => 'role-user', 'contract_admin' => false],
        ];

        [$toInsert, $skipped] = $this->invokeFilterExistingContractUserRecords($records);

        $this->assertSame(2, $skipped);
        $this->assertSame(
            [
                ['user_id' => 'user-1', 'contract_id' => 'contract-1', 'role_id' => 'role-user', 'contract_admin' => false],
                ['user_id' => 'user-3', 'contract_id' => 'contract-2', 'role_id' => 'role-user', 'contract_admin' => false],
            ],
            $toInsert
        );
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function invokeFilterExistingContractUserRecords(array $records): array
    {
        $method = new ReflectionMethod(EntityMigrator::class, 'filterExistingContractUserRecords');

        return $method->invoke(new EntityMigrator(), $records);
    }
}
