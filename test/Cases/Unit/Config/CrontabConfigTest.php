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

namespace HyperfTest\Cases\Unit\Config;

use App\Crontab\SeedLayoutsAdminLookupTask;
use Hyperf\Crontab\Crontab;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Configuração do hyperf/crontab em config/autoload/crontab.php.
 *
 * @internal
 */
#[CoversNothing]
final class CrontabConfigTest extends UnitTestCase
{
    private const CONFIG_FILE = __DIR__ . '/../../../../config/autoload/crontab.php';

    public function testCrontabIsEnabledByDefault(): void
    {
        $this->setEnvValue('CRONTAB_ENABLE', null);

        $this->assertTrue($this->loadConfig()['enable']);
    }

    public function testCrontabCanBeDisabledByEnv(): void
    {
        $this->setEnvValue('CRONTAB_ENABLE', 'false');

        $this->assertFalse($this->loadConfig()['enable']);
    }

    public function testSeedLayoutsAdminTaskRunsEveryMinuteOnOneServerWithoutOverlap(): void
    {
        $crontabs = $this->loadConfig()['crontab'];

        $this->assertCount(1, $crontabs);
        $crontab = $crontabs[0];
        $this->assertInstanceOf(Crontab::class, $crontab);
        $this->assertSame('seed-lookup-layouts-admin', $crontab->getName());
        $this->assertSame('* * * * *', $crontab->getRule());
        $this->assertSame('callback', $crontab->getType());
        $this->assertSame([SeedLayoutsAdminLookupTask::class, 'execute'], $crontab->getCallback());
        $this->assertTrue($crontab->isSingleton());
        $this->assertTrue($crontab->isOnOneServer());
        $this->assertSame('default', $crontab->getMutexPool());
        $this->assertGreaterThanOrEqual(60, $crontab->getMutexExpires());
        $this->assertLessThanOrEqual(120, $crontab->getMutexExpires());
        $this->assertTrue($crontab->isEnable());
        $this->assertSame([], $crontab->getEnvironments());
    }

    /**
     * @return array{enable: bool, crontab: list<mixed>}
     */
    private function loadConfig(): array
    {
        return require self::CONFIG_FILE;
    }
}
