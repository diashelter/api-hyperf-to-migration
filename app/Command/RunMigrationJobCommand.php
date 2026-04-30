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

namespace App\Command;

use App\Service\MigrationOrchestrator;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

#[Command]
class RunMigrationJobCommand extends HyperfCommand
{
    protected ?string $name = 'migration:run-job';

    protected string $description = 'Executa diretamente um job de migração existente, sem reenfileirar no Redis.';

    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->addArgument('job_id', InputArgument::REQUIRED, 'UUID do job de migração existente.');
    }

    public function handle(): void
    {
        $jobId = (string) $this->input->getArgument('job_id');

        $this->line("Executando migration job <info>{$jobId}</info>...");

        $this->container->get(MigrationOrchestrator::class)->run($jobId);

        $this->info('Execução finalizada.');
    }
}
