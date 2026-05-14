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

use App\Exception\ValidationFailedException;
use App\Service\LegacyConnectionFactory;
use Hyperf\Contract\ConfigInterface;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @internal
 */
#[CoversClass(LegacyConnectionFactory::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LegacyConnectionFactoryTest extends UnitTestCase
{
    public function testConnectCreatesOneIsolatedConnectionPerLegacyDatabase(): void
    {
        $baseConfig = [
            'driver' => 'pgsql',
            'host' => 'legacy-host',
            'database' => null,
        ];
        $registered = [];

        $config = Mockery::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->twice()
            ->with('databases.legacy_database')
            ->andReturn($baseConfig);
        $config->shouldReceive('set')
            ->twice()
            ->withArgs(function (string $key, array $value) use (&$registered): bool {
                $registered[$key] = $value;

                return str_starts_with($key, 'databases.legacy_database_');
            });

        $connection = Mockery::mock();
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $db->shouldReceive('connection')
            ->once()
            ->with(Mockery::on(static fn (string $name): bool => str_contains($name, 'cont_acessus')))
            ->andReturn($connection);
        $db->shouldReceive('connection')
            ->once()
            ->with(Mockery::on(static fn (string $name): bool => str_contains($name, 'cont_krypton')))
            ->andReturn($connection);
        $connection->shouldReceive('selectOne')
            ->twice()
            ->with('SELECT current_database() AS db')
            ->andReturn((object) ['db' => 'cont_acessus'], (object) ['db' => 'cont_krypton']);

        $factory = new LegacyConnectionFactory();
        $this->injectProperty($factory, 'config', $config);

        $first = $factory->connect('cont_acessus');
        $second = $factory->connect('cont_krypton');

        $this->assertNotSame($first, $second);
        $this->assertSame('cont_acessus', $registered["databases.{$first}"]['database']);
        $this->assertSame('cont_krypton', $registered["databases.{$second}"]['database']);
    }

    public function testConnectFailsWhenCurrentDatabaseDoesNotMatchRequestedLegacyDatabase(): void
    {
        $config = Mockery::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->once()
            ->with('databases.legacy_database')
            ->andReturn(['driver' => 'pgsql', 'database' => null]);
        $config->shouldReceive('set')->once();

        $connection = Mockery::mock();
        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $db->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('selectOne')
            ->once()
            ->with('SELECT current_database() AS db')
            ->andReturn((object) ['db' => 'cont_aldebaran']);

        $factory = new LegacyConnectionFactory();
        $this->injectProperty($factory, 'config', $config);

        $this->expectException(ValidationFailedException::class);
        $this->expectExceptionMessage("expected 'cont_acessus', got 'cont_aldebaran'");

        $factory->connect('cont_acessus');
    }
}
