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

use HyperfTest\UnitTestCase;
use Monolog\Handler\RotatingFileHandler;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Configuração do logger em config/autoload/logger.php.
 *
 * @internal
 */
#[CoversNothing]
final class LoggerConfigTest extends UnitTestCase
{
    private const CONFIG_FILE = __DIR__ . '/../../../../config/autoload/logger.php';

    public function testDefaultLoggerRotatesDailyKeepingSevenFilesByDefault(): void
    {
        $this->setEnvValue('LOG_MAX_FILES', null);

        $handler = $this->loadConfig()['default']['handler'];

        $this->assertSame(RotatingFileHandler::class, $handler['class']);
        $this->assertStringEndsWith('/runtime/logs/hyperf.log', $handler['constructor']['filename']);
        $this->assertSame(7, $handler['constructor']['maxFiles']);
    }

    public function testRetentionComesFromEnv(): void
    {
        $this->setEnvValue('LOG_MAX_FILES', '14');

        $this->assertSame(14, $this->loadConfig()['default']['handler']['constructor']['maxFiles']);
    }

    public function testRetentionNeverDisablesCleanup(): void
    {
        // maxFiles = 0 no Monolog significa "nunca apagar", o que voltaria ao crescimento sem limite.
        $this->setEnvValue('LOG_MAX_FILES', '0');

        $this->assertSame(1, $this->loadConfig()['default']['handler']['constructor']['maxFiles']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadConfig(): array
    {
        // O bootstrap dos testes unitários não define BASE_PATH (quem define é bin/hyperf.php).
        defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 4));

        return require self::CONFIG_FILE;
    }
}
