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

namespace App\Crontab;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Crontab\LoggerInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Logger do hyperf/crontab (bind em config/autoload/dependencies.php).
 *
 * O pacote loga em info a cada execução ("executed successfully", "skipped ... caused by
 * server mutex") e em debug a cada ciclo do dispatcher. Para não gerar uma linha por minuto
 * sem mudança, só warning ou acima chega ao StdoutLogger; as tarefas logam o que importa
 * nos próprios loggers.
 */
class WarningLevelCrontabLogger extends AbstractLogger implements LoggerInterface
{
    private const FORWARDED_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
    ];

    #[Inject]
    protected StdoutLoggerInterface $stdoutLogger;

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (! in_array($level, self::FORWARDED_LEVELS, true)) {
            return;
        }

        $this->stdoutLogger->log($level, $message, $context);
    }
}
