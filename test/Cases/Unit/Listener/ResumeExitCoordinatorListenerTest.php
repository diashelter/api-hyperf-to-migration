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

namespace HyperfTest\Cases\Unit\Listener;

use App\Listener\ResumeExitCoordinatorListener;
use Hyperf\Command\Command;
use Hyperf\Command\Event\AfterExecute;
use Hyperf\Coordinator\Constants;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @internal
 */
#[CoversClass(ResumeExitCoordinatorListener::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ResumeExitCoordinatorListenerTest extends UnitTestCase
{
    public function testListenReturnsTheAfterExecuteEvent(): void
    {
        $listener = new ResumeExitCoordinatorListener();

        $this->assertSame([AfterExecute::class], $listener->listen());
    }

    public function testProcessResumesTheWorkerExitCoordinator(): void
    {
        $coordinator = Mockery::mock();
        $coordinator->shouldReceive('resume')->once();

        $manager = Mockery::mock('alias:Hyperf\Coordinator\CoordinatorManager');
        $manager->shouldReceive('until')->once()->with(Constants::WORKER_EXIT)->andReturn($coordinator);

        $command = $this->createMock(Command::class);

        (new ResumeExitCoordinatorListener())->process(new AfterExecute($command));

        $this->assertTrue(true);
    }
}
