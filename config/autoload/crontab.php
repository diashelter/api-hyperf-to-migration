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
use App\Crontab\SeedLayoutsAdminLookupTask;
use Hyperf\Crontab\Crontab;

use function Hyperf\Support\env;

/*
 * Agendamentos do migrator (hyperf/crontab).
 *
 * seed-lookup-layouts-admin: equivale a `php bin/hyperf.php migration:seed-lookups layouts_admin`
 * a cada minuto, em um container só e sem sobreposição — o mesmo regime do
 * `Schedule::job(new SyncAdminLayoutsJob)->everyMinute()->onOneServer()` do conciliadorweb
 * (routes/console.php:12). O nome não leva APP_ENV: a trava no Redis é global aos containers.
 * mutexExpires é a janela de failover se o container dono da trava cair sem desligar
 * (a trava é renovada a cada segundo enquanto o dono vive).
 */
return [
    // Ligado por padrão em todos os ambientes; CRONTAB_ENABLE=false desliga o dispatcher.
    'enable' => (bool) env('CRONTAB_ENABLE', true),
    'crontab' => [
        (new Crontab())
            ->setName('seed-lookup-layouts-admin')
            ->setRule('* * * * *')
            ->setType('callback')
            ->setCallback([SeedLayoutsAdminLookupTask::class, 'execute'])
            ->setSingleton(true)
            ->setOnOneServer(true)
            ->setMutexPool('default')
            ->setMutexExpires(90)
            ->setMemo('Atualiza o lookup_cache de layouts_admin a partir do conciliador_web'),
    ],
];
