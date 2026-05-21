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

use App\PullMode\Source\ContractSource;
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
}
