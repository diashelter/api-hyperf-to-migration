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
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

use function Hyperf\Support\env;

/*
 * This file is part of Hyperf.
 *
 * @see     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
/*
 * Um arquivo por dia (runtime/logs/hyperf-AAAA-MM-DD.log). Na virada do dia o Monolog apaga os
 * arquivos além de LOG_MAX_FILES (padrão 7 dias). O log SQL das migrações gera centenas de MB por
 * dia; sem rotação o arquivo único chegou a 1,7 GB em seis dias.
 */
return [
    'default' => [
        'handler' => [
            'class' => RotatingFileHandler::class,
            'constructor' => [
                'filename' => BASE_PATH . '/runtime/logs/hyperf.log',
                'maxFiles' => max(1, (int) env('LOG_MAX_FILES', 7)),
                'level' => Logger::DEBUG,
            ],
        ],
        'formatter' => [
            'class' => LineFormatter::class,
            'constructor' => [
                'format' => null,
                'dateFormat' => 'Y-m-d H:i:s',
                'allowInlineLineBreaks' => true,
            ],
        ],
    ],
];
