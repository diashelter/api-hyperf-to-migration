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
use App\Crontab\WarningLevelCrontabLogger;
use Hyperf\Crontab\LoggerInterface as CrontabLoggerInterface;

return [
    // Logs do hyperf/crontab: só warning ou acima (RNF4 da spec agendamento-seed-lookup-layouts-admin).
    CrontabLoggerInterface::class => WarningLevelCrontabLogger::class,
];
