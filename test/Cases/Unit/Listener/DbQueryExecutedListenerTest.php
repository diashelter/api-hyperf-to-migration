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

use App\Listener\DbQueryExecutedListener;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Logger\LoggerFactory;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(DbQueryExecutedListener::class)]
final class DbQueryExecutedListenerTest extends UnitTestCase
{
    public function testListenReturnsTheQueryExecutedEvent(): void
    {
        $listener = $this->createListener($this->createStub(LoggerInterface::class));

        $this->assertSame([QueryExecuted::class], $listener->listen());
    }

    public function testProcessInterpolatesPositionalBindingsBeforeLogging(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with("[12.5] select * from users where id = '1' and status = 'active'");

        $listener = $this->createListener($logger);

        $listener->process(
            new QueryExecuted(
                'select * from users where id = ? and status = ?',
                [1, 'active'],
                12.5,
                $this->createConnection()
            )
        );
    }

    public function testProcessKeepsSqlUntouchedForAssociativeBindings(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('[7.1] select * from users where id = :id');

        $listener = $this->createListener($logger);

        $listener->process(
            new QueryExecuted(
                'select * from users where id = :id',
                ['id' => 1],
                7.1,
                $this->createConnection()
            )
        );
    }

    private function createListener(LoggerInterface $logger): DbQueryExecutedListener
    {
        $loggerFactory = $this->createMock(LoggerFactory::class);
        $loggerFactory->expects($this->once())
            ->method('get')
            ->with('sql')
            ->willReturn($logger);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with(LoggerFactory::class)
            ->willReturn($loggerFactory);

        return new DbQueryExecutedListener($container);
    }

    private function createConnection(): ConnectionInterface
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getName'])
            ->getMock();
        $connection->method('getName')->willReturn('default');

        return $connection;
    }
}
