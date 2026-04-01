<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\LookupCacheService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

#[Command]
class SeedLookupCacheCommand extends HyperfCommand
{
    protected ?string $name = 'migration:seed-lookups';

    protected string $description = 'Busca dados de referência do conciliador_web e popula o lookup_cache local. Execute uma vez por ambiente antes de subir o servidor.';

    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->addArgument(
            'entity',
            InputArgument::OPTIONAL,
            'Entidade específica para seed (roles|status|layouts_admin). Omitir para processar todas.',
        );
    }

    public function handle(): void
    {
        /** @var LookupCacheService $service */
        $service = $this->container->get(LookupCacheService::class);

        $entity = $this->input->getArgument('entity');

        if ($entity !== null) {
            $supported = LookupCacheService::supportedEntities();
            if (! in_array($entity, $supported, true)) {
                $this->error("Entidade '{$entity}' não suportada. Entidades válidas: " . implode(', ', $supported));
                return;
            }

            $this->line("Seeding lookup_cache para: <info>{$entity}</info>");
            $count = $service->seed($entity);
            $this->info("  → {$count} registro(s) upsertados para '{$entity}'.");
            return;
        }

        $this->line('Iniciando seed de todas as entidades de lookup...');
        $results = $service->seedAll();

        $total = 0;
        foreach ($results as $entityName => $count) {
            $this->line("  → <info>{$entityName}</info>: {$count} registro(s)");
            $total += $count;
        }

        $this->info("Seed concluído. Total: {$total} registro(s) upsertados.");
    }
}
