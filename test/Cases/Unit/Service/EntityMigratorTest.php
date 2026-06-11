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
use App\Service\IdMappingService;
use App\Trait\RecordPreparation;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionMethod;
use RuntimeException;

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

    public function testFilterExistingUserCompanyRestrictionRecordsSkipsExistingAndRepeatedTriples(): void
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
            ->with('user_company_restrictions')
            ->andReturn($builder);

        $builder->shouldReceive('select')
            ->once()
            ->with(['contract_id', 'user_id', 'company_id'])
            ->andReturnSelf();

        $builder->shouldReceive('whereIn')
            ->once()
            ->with('contract_id', ['contract-1'])
            ->andReturnSelf();

        $builder->shouldReceive('whereIn')
            ->once()
            ->with('user_id', ['user-1', 'user-2', 'user-3'])
            ->andReturnSelf();

        $builder->shouldReceive('whereIn')
            ->once()
            ->with('company_id', ['company-1', 'company-2'])
            ->andReturnSelf();

        $builder->shouldReceive('get')
            ->once()
            ->andReturn([
                (object) ['contract_id' => 'contract-1', 'user_id' => 'user-2', 'company_id' => 'company-1'],
            ]);

        $records = [
            ['contract_id' => 'contract-1', 'user_id' => 'user-1', 'company_id' => 'company-1'],
            ['contract_id' => 'contract-1', 'user_id' => 'user-1', 'company_id' => 'company-1'],
            ['contract_id' => 'contract-1', 'user_id' => 'user-2', 'company_id' => 'company-1'],
            ['contract_id' => 'contract-1', 'user_id' => 'user-3', 'company_id' => 'company-2'],
        ];

        [$toInsert, $skipped] = $this->invokeFilterExistingUserCompanyRestrictionRecords($records);

        $this->assertSame(2, $skipped);
        $this->assertSame(
            [
                ['contract_id' => 'contract-1', 'user_id' => 'user-1', 'company_id' => 'company-1'],
                ['contract_id' => 'contract-1', 'user_id' => 'user-3', 'company_id' => 'company-2'],
            ],
            $toInsert
        );
    }

    public function testResolveContractIdThrowsWhenRequiredContractMappingIsMissing(): void
    {
        $idMappingService = Mockery::mock(IdMappingService::class);
        $idMappingService->shouldReceive('resolve')
            ->once()
            ->with('contracts', 'cont_acessus', 'cont_acessus')
            ->andReturn(null);

        $preparation = new class {
            use RecordPreparation;

            /**
             * @param array<string, mixed> $record
             * @return array<string, mixed>
             */
            public function resolveContract(IdMappingService $service, array $record, string $contractId): array
            {
                return $this->recordPrepResolveContractIdFK($service, $record, $contractId);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Contract mapping not found for migration scope 'cont_acessus'.");

        $preparation->resolveContract($idMappingService, ['name' => 'ACME'], 'cont_acessus');
    }

    public function testTransientDatabaseRetryRetriesConnectionFailures(): void
    {
        $this->setEnvValue('MIGRATION_TRANSIENT_DB_RETRY_ATTEMPTS', '3');
        $this->setEnvValue('MIGRATION_TRANSIENT_DB_RETRY_DELAY_MS', '0');

        $attempts = 0;
        $method = new ReflectionMethod(EntityMigrator::class, 'withTransientDatabaseRetry');

        $result = $method->invoke(new EntityMigrator(), function () use (&$attempts): string {
            ++$attempts;

            if ($attempts < 3) {
                throw new RuntimeException('SQLSTATE[08006] [7] could not send SSL negotiation packet: Resource temporarily unavailable');
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $attempts);
    }

    public function testTransientDatabaseRetryDoesNotRetryPermanentFailures(): void
    {
        $this->setEnvValue('MIGRATION_TRANSIENT_DB_RETRY_ATTEMPTS', '3');
        $this->setEnvValue('MIGRATION_TRANSIENT_DB_RETRY_DELAY_MS', '0');

        $attempts = 0;
        $method = new ReflectionMethod(EntityMigrator::class, 'withTransientDatabaseRetry');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLSTATE[23505]');

        try {
            $method->invoke(new EntityMigrator(), function () use (&$attempts): string {
                ++$attempts;

                throw new RuntimeException('SQLSTATE[23505]: Unique violation');
            });
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    public function testEntityPipelineStallRequiresConfiguredTimeWithoutProgress(): void
    {
        $method = new ReflectionMethod(EntityMigrator::class, 'entityPipelineHasStalled');
        $migrator = new EntityMigrator();

        $this->assertFalse($method->invoke($migrator, 100.0, 900, 999.99));
        $this->assertTrue($method->invoke($migrator, 100.0, 900, 1000.0));
        $this->assertFalse($method->invoke($migrator, 100.0, 0, 1000.0));
    }

    public function testMappingsForSuccessfulInsertsKeepsOnlyRecordsFromSuccessfulChunks(): void
    {
        $method = new ReflectionMethod(EntityMigrator::class, 'mappingsForSuccessfulInserts');
        $migrator = new EntityMigrator();

        $mappings = [
            'legacy-1' => 'new-1',
            'legacy-2' => 'new-2',
            'legacy-3' => 'new-3',
        ];
        $insertResult = [
            'inserted' => 2,
            'failed' => 1,
            'successful_record_ids' => ['new-1', 'new-3'],
        ];

        $this->assertSame(
            [
                'legacy-1' => 'new-1',
                'legacy-3' => 'new-3',
            ],
            $method->invoke($migrator, $mappings, $insertResult)
        );
    }

    public function testMappingsForSuccessfulInsertsKeepsAllMappingsWhenBatchFullyInserted(): void
    {
        $method = new ReflectionMethod(EntityMigrator::class, 'mappingsForSuccessfulInserts');
        $migrator = new EntityMigrator();

        $mappings = [
            'legacy-1' => 'new-1',
            'legacy-2' => 'new-2',
        ];

        $this->assertSame(
            $mappings,
            $method->invoke($migrator, $mappings, ['inserted' => 2, 'failed' => 0])
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

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function invokeFilterExistingUserCompanyRestrictionRecords(array $records): array
    {
        $method = new ReflectionMethod(EntityMigrator::class, 'filterExistingUserCompanyRestrictionRecords');

        return $method->invoke(new EntityMigrator(), $records);
    }
}
