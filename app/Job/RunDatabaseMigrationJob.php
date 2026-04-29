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

namespace App\Job;

use App\Service\MigrationOrchestrator;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;

class RunDatabaseMigrationJob extends Job
{
    /**
     * Tentativas máximas pelo retry da fila. Como o orchestrator é idempotente
     * (pula entidades `completed` e usa `last_id` por entidade), o retry é seguro.
     */
    protected int $maxAttempts = 3;

    public function __construct(public readonly string $jobId)
    {
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $orchestrator = $container->get(MigrationOrchestrator::class);

        $orchestrator->run($this->jobId);
    }
}
