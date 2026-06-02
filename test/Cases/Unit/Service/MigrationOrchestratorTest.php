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

use App\PullMode\Source\AbstractLegacySource;
use App\PullMode\Source\ContractSource;
use App\Service\ContractCompanyCountSyncService;
use App\Service\EntityMetadataRegistry;
use App\Service\EntityMigrator;
use App\Service\ExportLayoutSyncService;
use App\Service\LegacyConnectionFactory;
use App\Service\MigrationJobService;
use App\Service\MigrationOrchestrator;
use Hyperf\Logger\LoggerFactory;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(MigrationOrchestrator::class)]
final class MigrationOrchestratorTest extends UnitTestCase
{
    public function testRunAbortsWhenContractsEntityFails(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('warning')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        $loggerFactory = Mockery::mock(LoggerFactory::class);
        $loggerFactory->shouldReceive('get')
            ->once()
            ->with('migration-orchestrator')
            ->andReturn($logger);

        $contractSource = new ContractSource();

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')
            ->once()
            ->with(ContractSource::class)
            ->andReturn($contractSource);

        $jobService = Mockery::mock(MigrationJobService::class);
        $jobService->shouldReceive('getStatus')
            ->once()
            ->with('job-1')
            ->andReturn([
                'legacy_db' => 'cont_arenasolucoes',
                'contract_id' => 'cont_arenasolucoes',
            ]);
        $jobService->shouldReceive('markProcessing')->once()->with('job-1');
        $jobService->shouldReceive('getEntityProgress')->once()->with('job-1', 'contracts')->andReturn([]);
        $jobService->shouldReceive('setCurrentEntity')->once()->with('job-1', 'contracts');
        $jobService->shouldReceive('markFailed')
            ->once()
            ->with('job-1', Mockery::on(static function (string $message): bool {
                return str_contains($message, "Critical entity 'contracts' failed")
                    && str_contains($message, 'Resource temporarily unavailable');
            }));
        $jobService->shouldNotReceive('markCompleted');

        $legacyConnectionFactory = Mockery::mock(LegacyConnectionFactory::class);
        $legacyConnectionFactory->shouldReceive('connect')
            ->once()
            ->with('cont_arenasolucoes')
            ->andReturn('legacy_connection');

        $exportLayoutSyncService = Mockery::mock(ExportLayoutSyncService::class);
        $exportLayoutSyncService->shouldReceive('sync')
            ->once()
            ->with('legacy_connection', 'cont_arenasolucoes');

        $entityMigrator = Mockery::mock(EntityMigrator::class);
        $entityMigrator->shouldReceive('migrate')
            ->once()
            ->with('job-1', $contractSource, 'legacy_connection', 'cont_arenasolucoes')
            ->andReturn([
                'status' => 'failed',
                'inserted' => 0,
                'failed' => 0,
                'skipped' => 0,
                'error_message' => 'SQLSTATE[08006] [7] could not send SSL negotiation packet: Resource temporarily unavailable',
            ]);

        $orchestrator = new MigrationOrchestrator($loggerFactory, $container);
        $this->injectProperty($orchestrator, 'jobService', $jobService);
        $this->injectProperty($orchestrator, 'legacyConnectionFactory', $legacyConnectionFactory);
        $this->injectProperty($orchestrator, 'exportLayoutSyncService', $exportLayoutSyncService);
        $this->injectProperty($orchestrator, 'entityMigrator', $entityMigrator);

        $orchestrator->run('job-1');
        $this->addToAssertionCount(1);
    }

    public function testRunSyncsContractCompanyCountWhenAllEntitiesCompleteWithoutErrors(): void
    {
        [$loggerFactory, $container, $jobService, $legacyConnectionFactory, $exportLayoutSyncService, $entityMigrator]
            = $this->createSuccessfulRunDependencies();

        $companyCountSyncService = Mockery::mock(ContractCompanyCountSyncService::class);
        $companyCountSyncService->shouldReceive('sync')->once()->with('cont_arenasolucoes');

        $jobService->shouldReceive('markCompleted')->once()->with('job-1', null);

        $orchestrator = new MigrationOrchestrator($loggerFactory, $container);
        $this->injectProperty($orchestrator, 'jobService', $jobService);
        $this->injectProperty($orchestrator, 'legacyConnectionFactory', $legacyConnectionFactory);
        $this->injectProperty($orchestrator, 'exportLayoutSyncService', $exportLayoutSyncService);
        $this->injectProperty($orchestrator, 'entityMigrator', $entityMigrator);
        $this->injectProperty($orchestrator, 'contractCompanyCountSyncService', $companyCountSyncService);

        $orchestrator->run('job-1');
        $this->addToAssertionCount(1);
    }

    public function testRunDoesNotSyncContractCompanyCountWhenAnyEntityCompletesWithErrors(): void
    {
        [$loggerFactory, $container, $jobService, $legacyConnectionFactory, $exportLayoutSyncService, $entityMigrator]
            = $this->createSuccessfulRunDependencies([
                'users' => [
                    'status' => 'completed_with_errors',
                    'inserted' => 1,
                    'failed' => 1,
                    'skipped' => 0,
                    'error_message' => 'one user failed',
                ],
            ]);

        $companyCountSyncService = Mockery::mock(ContractCompanyCountSyncService::class);
        $companyCountSyncService->shouldNotReceive('sync');

        $jobService->shouldReceive('markCompleted')
            ->once()
            ->with('job-1', Mockery::on(static function (array $errorSummary): bool {
                return ($errorSummary['users'] ?? null) === 'one user failed';
            }));

        $orchestrator = new MigrationOrchestrator($loggerFactory, $container);
        $this->injectProperty($orchestrator, 'jobService', $jobService);
        $this->injectProperty($orchestrator, 'legacyConnectionFactory', $legacyConnectionFactory);
        $this->injectProperty($orchestrator, 'exportLayoutSyncService', $exportLayoutSyncService);
        $this->injectProperty($orchestrator, 'entityMigrator', $entityMigrator);
        $this->injectProperty($orchestrator, 'contractCompanyCountSyncService', $companyCountSyncService);

        $orchestrator->run('job-1');
        $this->addToAssertionCount(1);
    }

    public function testRunMarksCompletedWithErrorsWhenContractCompanyCountSyncFails(): void
    {
        [$loggerFactory, $container, $jobService, $legacyConnectionFactory, $exportLayoutSyncService, $entityMigrator]
            = $this->createSuccessfulRunDependencies();

        $companyCountSyncService = Mockery::mock(ContractCompanyCountSyncService::class);
        $companyCountSyncService->shouldReceive('sync')
            ->once()
            ->with('cont_arenasolucoes')
            ->andThrow(new RuntimeException('Manager API returned HTTP 404 while fetching company_count.'));

        $jobService->shouldReceive('markFailed')->never();
        $jobService->shouldReceive('markCompleted')
            ->once()
            ->with('job-1', Mockery::on(static function (array $errorSummary): bool {
                return ($errorSummary['contract_company_count'] ?? null)
                    === 'Manager API returned HTTP 404 while fetching company_count.';
            }));

        $orchestrator = new MigrationOrchestrator($loggerFactory, $container);
        $this->injectProperty($orchestrator, 'jobService', $jobService);
        $this->injectProperty($orchestrator, 'legacyConnectionFactory', $legacyConnectionFactory);
        $this->injectProperty($orchestrator, 'exportLayoutSyncService', $exportLayoutSyncService);
        $this->injectProperty($orchestrator, 'entityMigrator', $entityMigrator);
        $this->injectProperty($orchestrator, 'contractCompanyCountSyncService', $companyCountSyncService);

        $orchestrator->run('job-1');
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, array<string, mixed>> $resultsByEntity
     * @return array{0: LoggerFactory, 1: ContainerInterface, 2: MigrationJobService, 3: LegacyConnectionFactory, 4: ExportLayoutSyncService, 5: EntityMigrator}
     */
    private function createSuccessfulRunDependencies(array $resultsByEntity = []): array
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('warning')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        $loggerFactory = Mockery::mock(LoggerFactory::class);
        $loggerFactory->shouldReceive('get')
            ->once()
            ->with('migration-orchestrator')
            ->andReturn($logger);

        $sourcesByClass = [];
        foreach (EntityMetadataRegistry::sources() as $sourceClass) {
            $entity = (new $sourceClass())->entity();
            $source = Mockery::mock(AbstractLegacySource::class);
            $source->shouldReceive('entity')->andReturn($entity);
            $sourcesByClass[$sourceClass] = $source;
        }

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')
            ->times(count($sourcesByClass))
            ->andReturnUsing(static fn (string $sourceClass): AbstractLegacySource => $sourcesByClass[$sourceClass]);

        $jobService = Mockery::mock(MigrationJobService::class);
        $jobService->shouldReceive('getStatus')
            ->once()
            ->with('job-1')
            ->andReturn([
                'legacy_db' => 'cont_arenasolucoes',
                'contract_id' => 'cont_arenasolucoes',
            ]);
        $jobService->shouldReceive('markProcessing')->once()->with('job-1');
        $jobService->shouldReceive('getEntityProgress')->andReturn([]);
        $jobService->shouldReceive('setCurrentEntity');

        $legacyConnectionFactory = Mockery::mock(LegacyConnectionFactory::class);
        $legacyConnectionFactory->shouldReceive('connect')
            ->once()
            ->with('cont_arenasolucoes')
            ->andReturn('legacy_connection');

        $exportLayoutSyncService = Mockery::mock(ExportLayoutSyncService::class);
        $exportLayoutSyncService->shouldReceive('sync')
            ->once()
            ->with('legacy_connection', 'cont_arenasolucoes');

        $entityMigrator = Mockery::mock(EntityMigrator::class);
        foreach ($sourcesByClass as $source) {
            $entity = $source->entity();
            $entityMigrator->shouldReceive('migrate')
                ->once()
                ->with('job-1', $source, 'legacy_connection', 'cont_arenasolucoes')
                ->andReturn($resultsByEntity[$entity] ?? [
                    'status' => 'completed',
                    'inserted' => 1,
                    'failed' => 0,
                    'skipped' => 0,
                ]);
        }

        return [$loggerFactory, $container, $jobService, $legacyConnectionFactory, $exportLayoutSyncService, $entityMigrator];
    }
}
