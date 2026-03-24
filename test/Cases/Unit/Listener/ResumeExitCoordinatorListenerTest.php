<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Listener;

use App\Listener\ResumeExitCoordinatorListener;
use Hyperf\Command\Command;
use Hyperf\Command\Event\AfterExecute;
use Hyperf\Coordinator\Constants;
use Mockery;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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
