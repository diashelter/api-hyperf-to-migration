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

use App\Crontab\WarningLevelCrontabLogger;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Crontab\LoggerInterface;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(WarningLevelCrontabLogger::class)]
final class WarningLevelCrontabLoggerTest extends UnitTestCase
{
    /**
     * Chamadas repassadas ao StdoutLogger: lista de [nível, mensagem, contexto].
     *
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $forwarded = [];

    public function testImplementsCrontabLoggerInterface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, $this->createLogger());
    }

    public function testDiscardsInfoNoticeAndDebug(): void
    {
        $logger = $this->createLogger();

        $logger->info('Crontab task [seed-lookup-layouts-admin] executed successfully at 2026-11-23 10:00:00.');
        $logger->notice('notice');
        $logger->debug('Crontab dispatcher sleep 59s.');

        $this->assertSame([], $this->forwarded);
    }

    public function testForwardsWarningAndAboveToStdoutLogger(): void
    {
        $logger = $this->createLogger();

        $logger->warning('Crontab seed-lookup-layouts-admin is disabled.');
        $logger->error('Crontab task [seed-lookup-layouts-admin] failed execution.', ['k' => 'v']);
        $logger->critical('critical');
        $logger->alert('alert');
        $logger->emergency('emergency');

        $this->assertSame([
            ['warning', 'Crontab seed-lookup-layouts-admin is disabled.', []],
            ['error', 'Crontab task [seed-lookup-layouts-admin] failed execution.', ['k' => 'v']],
            ['critical', 'critical', []],
            ['alert', 'alert', []],
            ['emergency', 'emergency', []],
        ], $this->forwarded);
    }

    private function createLogger(): WarningLevelCrontabLogger
    {
        $stdout = Mockery::mock(StdoutLoggerInterface::class);
        $stdout->shouldReceive('log')
            ->andReturnUsing(function (string $level, string $message, array $context = []): void {
                $this->forwarded[] = [$level, $message, $context];
            });

        $logger = new WarningLevelCrontabLogger();
        $this->injectProperty($logger, 'stdoutLogger', $stdout);

        return $logger;
    }
}
