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

namespace HyperfTest\Cases\Unit\Crontab;

use App\Crontab\SeedLayoutsAdminLookupTask;
use App\Listener\DbQueryExecutedListener;
use App\Service\LookupCacheService;
use App\Service\LookupSeedResult;
use Hyperf\Context\Context;
use Hyperf\Logger\LoggerFactory;
use HyperfTest\UnitTestCase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(SeedLayoutsAdminLookupTask::class)]
final class SeedLayoutsAdminLookupTaskTest extends UnitTestCase
{
    /**
     * Logs recebidos pelo logger mockado: lista de [nível, mensagem, contexto].
     *
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $logs = [];

    /**
     * Entidades pedidas ao LookupCacheService::seedWithChanges().
     *
     * @var list<string>
     */
    private array $seededEntities = [];

    /**
     * Valor da flag de silêncio do log SQL no Context, visto de dentro do seedWithChanges().
     */
    private mixed $skipSqlLogDuringSeed = null;

    public function testExecuteLogsInfoWithCountsWhenSeedHasChanges(): void
    {
        $task = $this->createTask(new LookupSeedResult('layouts_admin', 1222, 2, 1, 3));

        $task->execute();

        $this->assertSame([[
            'info',
            'lookup_cache layouts_admin sincronizado',
            ['total' => 1222, 'inserted' => 2, 'updated' => 1, 'removed' => 3],
        ]], $this->logs);
    }

    public function testExecuteDoesNotLogInfoWhenSeedHasNoChanges(): void
    {
        $task = $this->createTask(new LookupSeedResult('layouts_admin', 1222, 0, 0, 0));

        $task->execute();

        $this->assertSame([], $this->logs);
    }

    public function testExecuteLogsErrorAndDoesNotRethrowWhenSeedFails(): void
    {
        $task = $this->createTask(new RuntimeException('conexão recusada'));

        $task->execute();

        $this->assertSame([[
            'error',
            'Falha ao sincronizar lookup_cache layouts_admin',
            ['exception' => RuntimeException::class, 'message' => 'conexão recusada'],
        ]], $this->logs);
    }

    public function testExecuteSeedsOnlyLayoutsAdmin(): void
    {
        $task = $this->createTask(new LookupSeedResult('layouts_admin', 0, 0, 0, 0));

        $task->execute();

        $this->assertSame(['layouts_admin'], $this->seededEntities);
    }

    public function testExecuteSilencesSqlLogOnlyDuringSeed(): void
    {
        $task = $this->createTask(new LookupSeedResult('layouts_admin', 1222, 0, 0, 0));

        $task->execute();

        $this->assertTrue($this->skipSqlLogDuringSeed);
        $this->assertFalse(Context::has(DbQueryExecutedListener::SKIP_LOG_CONTEXT_KEY));
    }

    public function testExecuteClearsSqlLogFlagWhenSeedFails(): void
    {
        $task = $this->createTask(new RuntimeException('conexão recusada'));

        $task->execute();

        $this->assertTrue($this->skipSqlLogDuringSeed);
        $this->assertFalse(Context::has(DbQueryExecutedListener::SKIP_LOG_CONTEXT_KEY));
    }

    private function createTask(LookupSeedResult|RuntimeException $outcome): SeedLayoutsAdminLookupTask
    {
        /** @var LookupCacheService&MockInterface $service */
        $service = Mockery::mock(LookupCacheService::class);
        $service->shouldNotReceive('seed', 'seedAll');
        $service->shouldReceive('seedWithChanges')
            ->andReturnUsing(function (string $entity) use ($outcome): LookupSeedResult {
                $this->seededEntities[] = $entity;
                $this->skipSqlLogDuringSeed = Context::get(DbQueryExecutedListener::SKIP_LOG_CONTEXT_KEY);

                if ($outcome instanceof RuntimeException) {
                    throw $outcome;
                }

                return $outcome;
            });

        $logger = Mockery::mock(LoggerInterface::class);
        foreach (['info', 'error', 'warning', 'notice', 'debug'] as $level) {
            $logger->shouldReceive($level)
                ->andReturnUsing(function (string $message, array $context = []) use ($level): void {
                    $this->logs[] = [$level, $message, $context];
                });
        }

        $loggerFactory = Mockery::mock(LoggerFactory::class);
        $loggerFactory->shouldReceive('get')->with('lookup-cache')->andReturn($logger);

        $task = new SeedLayoutsAdminLookupTask();
        $this->injectProperty($task, 'lookupCacheService', $service);
        $this->injectProperty($task, 'loggerFactory', $loggerFactory);

        return $task;
    }
}
