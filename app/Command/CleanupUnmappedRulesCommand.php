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

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Symfony\Component\Console\Input\InputArgument;

#[Command]
class CleanupUnmappedRulesCommand extends HyperfCommand
{
    protected ?string $name = 'migration:cleanup-unmapped-rules';

    protected string $description = 'Remove rules do destino que nao possuem mapping local para o escopo informado.';

    public function configure(): void
    {
        parent::configure();
        $this->addArgument('migration_scope', InputArgument::REQUIRED, 'Escopo/legacy_db da migracao.');
    }

    public function handle(): void
    {
        $scope = (string) $this->input->getArgument('migration_scope');
        $contractId = Db::table('migration_id_mappings')
            ->where('contract_id', $scope)
            ->where('entity', 'contracts')
            ->where('legacy_id', $scope)
            ->value('new_id');

        if (! is_string($contractId) || $contractId === '') {
            $this->error("Contrato '{$scope}' nao encontrado em migration_id_mappings.");
            return;
        }

        $lastId = null;
        $checked = 0;
        $deleted = 0;

        while (true) {
            $query = Db::connection('conciliador_web')
                ->table('rules')
                ->where('contract_id', $contractId)
                ->orderBy('id')
                ->limit(5000);

            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $ids = array_map(
                static fn (mixed $id): string => (string) $id,
                $query->pluck('id')->all()
            );

            if ($ids === []) {
                break;
            }

            $mapped = Db::table('migration_id_mappings')
                ->where('contract_id', $scope)
                ->where('entity', 'rules')
                ->whereIn('new_id', $ids)
                ->pluck('new_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $mappedSet = array_fill_keys($mapped, true);
            $unmapped = array_values(array_filter(
                $ids,
                static fn (string $id): bool => ! isset($mappedSet[$id])
            ));

            if ($unmapped !== []) {
                Db::connection('conciliador_web')
                    ->table('rules')
                    ->whereIn('id', $unmapped)
                    ->delete();
                $deleted += count($unmapped);
            }

            $checked += count($ids);
            $lastId = end($ids) ?: null;

            $this->line("Verificados {$checked}; removidos {$deleted}.");

            if (count($ids) < 5000) {
                break;
            }
        }

        $this->info("Limpeza concluida. Verificados {$checked}; removidos {$deleted}.");
    }
}
