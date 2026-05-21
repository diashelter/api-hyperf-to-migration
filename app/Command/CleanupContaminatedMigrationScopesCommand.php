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
use Symfony\Component\Console\Input\InputOption;

#[Command]
class CleanupContaminatedMigrationScopesCommand extends HyperfCommand
{
    private const DEFAULT_SCOPES = [
        'cont_acessus',
        'cont_arenasolucoes',
        'cont_krypton',
    ];

    private const MAPPED_TARGET_STEPS = [
        ['entity' => 'confrontation_records', 'table' => 'confrontation_records'],
        ['entity' => 'confrontations', 'table' => 'confrontations'],
        ['entity' => 'ignored_conciliation_terms', 'table' => 'ignored_conciliation_terms'],
        ['entity' => 'import_records', 'table' => 'import_records'],
        ['entity' => 'rules', 'table' => 'rules'],
        ['entity' => 'import_sessions', 'table' => 'import_sessions'],
        ['entity' => 'imports', 'table' => 'imports'],
        ['entity' => 'people_vinculated', 'table' => 'people_vinculated'],
        ['entity' => 'peoples', 'table' => 'peoples'],
        ['entity' => 'company_layout_fixed_accounts', 'table' => 'company_layout_fixed_accounts'],
        ['entity' => 'company_layout', 'table' => 'company_layout'],
        ['entity' => 'companies', 'table' => 'companies'],
        ['entity' => 'plan_items', 'table' => 'plan_items'],
        ['entity' => 'layouts', 'table' => 'layouts'],
        ['entity' => 'rules_sharings', 'table' => 'rules_sharings'],
        ['entity' => 'plans', 'table' => 'plans'],
    ];

    protected ?string $name = 'migration:cleanup-contaminated-scopes';

    protected string $description = 'Remove dados/mappings de escopos migrados com conexão legada contaminada. Dry-run por padrão.';

    public function configure(): void
    {
        parent::configure();

        $this->addArgument(
            'scopes',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Escopos/legacy_db para limpar. Default: ' . implode(' ', self::DEFAULT_SCOPES)
        );
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Aplica os deletes. Sem esta opção, roda apenas dry-run.');
        $this->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Quantidade de mappings processados por chunk.', 1000);
    }

    public function handle(): void
    {
        $scopes = $this->normalizeScopes((array) $this->input->getArgument('scopes'));
        $execute = (bool) $this->input->getOption('execute');
        $chunkSize = max(1, (int) $this->input->getOption('chunk-size'));

        $this->line(sprintf(
            '%s cleanup para escopos: <info>%s</info>',
            $execute ? 'Executando' : 'Dry-run de',
            implode(', ', $scopes)
        ));

        if (! $execute) {
            $this->warn('Nenhum dado sera apagado sem --execute.');
        }

        $contractIds = $this->contractIdsByScope($scopes);
        $missingContracts = array_values(array_diff($scopes, array_keys($contractIds)));

        if ($missingContracts !== []) {
            $this->warn('Sem mapping de contracts para: ' . implode(', ', $missingContracts));
        }

        $this->cleanupConfrontationConciliations($scopes, $execute);
        $this->cleanupPivotByContract('user_company_restrictions', $contractIds, $execute);
        $this->cleanupPivotByContract('contract_user', $contractIds, $execute);

        foreach (self::MAPPED_TARGET_STEPS as $step) {
            $this->cleanupMappedTargetRows(
                (string) $step['entity'],
                (string) $step['table'],
                $scopes,
                $execute,
                $chunkSize
            );
        }

        $this->cleanupMappedTargetRows('contracts', 'contracts', $scopes, $execute, $chunkSize);
        $this->cleanupMappings($scopes, $execute);

        $this->info($execute ? 'Limpeza aplicada.' : 'Dry-run concluido.');
    }

    /**
     * @param array<int, mixed> $rawScopes
     * @return array<int, string>
     */
    private function normalizeScopes(array $rawScopes): array
    {
        $scopes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $scope): string => trim((string) $scope),
            $rawScopes
        ))));

        return $scopes === [] ? self::DEFAULT_SCOPES : $scopes;
    }

    /**
     * @param array<int, string> $scopes
     * @return array<string, string>
     */
    private function contractIdsByScope(array $scopes): array
    {
        $rows = Db::table('migration_id_mappings')
            ->select(['contract_id', 'new_id'])
            ->where('entity', 'contracts')
            ->whereIn('contract_id', $scopes)
            ->get();

        $contractIds = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $scope = isset($row['contract_id']) ? (string) $row['contract_id'] : '';
            $newId = isset($row['new_id']) ? (string) $row['new_id'] : '';

            if ($scope !== '' && $newId !== '') {
                $contractIds[$scope] = $newId;
            }
        }

        return $contractIds;
    }

    /**
     * @param array<int, string> $scopes
     */
    private function cleanupConfrontationConciliations(array $scopes, bool $execute): void
    {
        $confrontationIds = $this->allMappedIds('confrontations', $scopes);
        $recordIds = $this->allMappedIds('confrontation_records', $scopes);

        if ($confrontationIds === [] && $recordIds === []) {
            $this->line('confrontation_conciliations: 0');
            return;
        }

        $query = $this->confrontationConciliationsQuery($confrontationIds, $recordIds);
        $count = $execute ? (int) $query->delete() : (int) $query->count();

        $this->line(sprintf(
            'confrontation_conciliations: %s=%d',
            $execute ? 'deleted' : 'would_delete',
            $count
        ));
    }

    /**
     * @param array<int, string> $confrontationIds
     * @param array<int, string> $recordIds
     */
    private function confrontationConciliationsQuery(array $confrontationIds, array $recordIds): mixed
    {
        $query = Db::connection('conciliador_web')->table('confrontation_conciliations');
        $hasWhere = false;

        if ($confrontationIds !== []) {
            $query->whereIn('confrontation_id', $confrontationIds);
            $hasWhere = true;
        }

        if ($recordIds !== []) {
            if ($hasWhere) {
                $query->orWhereIn('confrontation_records_bank', $recordIds)
                    ->orWhereIn('confrontation_records_financial', $recordIds);
            } else {
                $query->whereIn('confrontation_records_bank', $recordIds)
                    ->orWhereIn('confrontation_records_financial', $recordIds);
            }
        }

        return $query;
    }

    /**
     * @param array<int, string> $scopes
     */
    private function cleanupMappedTargetRows(
        string $entity,
        string $table,
        array $scopes,
        bool $execute,
        int $chunkSize
    ): void {
        $lastMappingId = null;
        $mapped = 0;
        $protected = 0;
        $affected = 0;

        while (true) {
            $query = Db::table('migration_id_mappings')
                ->select(['id', 'new_id'])
                ->where('entity', $entity)
                ->whereIn('contract_id', $scopes)
                ->orderBy('id')
                ->limit($chunkSize);

            if ($lastMappingId !== null) {
                $query->where('id', '>', $lastMappingId);
            }

            $rows = $query->get();
            $ids = [];
            $rowCount = 0;

            foreach ($rows as $row) {
                ++$rowCount;
                $row = (array) $row;
                $lastMappingId = isset($row['id']) ? (string) $row['id'] : $lastMappingId;

                if (! empty($row['new_id'])) {
                    $ids[] = (string) $row['new_id'];
                }
            }

            if ($rowCount === 0) {
                break;
            }

            $ids = array_values(array_unique($ids));

            if ($ids === []) {
                continue;
            }

            $mapped += count($ids);
            $sharedIds = $this->sharedMappedIds($ids, $scopes);
            $protected += count($sharedIds);
            $deletableIds = array_values(array_diff($ids, $sharedIds));

            if ($deletableIds !== []) {
                $affected += $this->affectTargetIds($table, $deletableIds, $execute);
            }

            if ($rowCount < $chunkSize) {
                break;
            }
        }

        $this->line(sprintf(
            '%s: mappings=%d protected_shared=%d %s=%d',
            $table,
            $mapped,
            $protected,
            $execute ? 'deleted' : 'would_delete',
            $affected
        ));
    }

    /**
     * @param array<int, string> $ids
     * @param array<int, string> $scopes
     * @return array<int, string>
     */
    private function sharedMappedIds(array $ids, array $scopes): array
    {
        $shared = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = Db::table('migration_id_mappings')
                ->whereIn('new_id', $chunk)
                ->whereNotIn('contract_id', $scopes)
                ->pluck('new_id');

            foreach ($rows as $id) {
                $shared[(string) $id] = true;
            }
        }

        return array_keys($shared);
    }

    /**
     * @param array<int, string> $ids
     */
    private function affectTargetIds(string $table, array $ids, bool $execute): int
    {
        $affected = 0;

        foreach (array_chunk($ids, 1000) as $chunk) {
            $query = Db::connection('conciliador_web')
                ->table($table)
                ->whereIn('id', $chunk);

            $affected += $execute ? (int) $query->delete() : (int) $query->count();
        }

        return $affected;
    }

    /**
     * @param array<string, string> $contractIds
     */
    private function cleanupPivotByContract(string $table, array $contractIds, bool $execute): void
    {
        if ($contractIds === []) {
            $this->line("{$table}: 0");
            return;
        }

        $query = Db::connection('conciliador_web')
            ->table($table)
            ->whereIn('contract_id', array_values($contractIds));

        $affected = $execute ? (int) $query->delete() : (int) $query->count();

        $this->line(sprintf(
            '%s: %s=%d',
            $table,
            $execute ? 'deleted' : 'would_delete',
            $affected
        ));
    }

    /**
     * @param array<int, string> $scopes
     * @return array<int, string>
     */
    private function allMappedIds(string $entity, array $scopes): array
    {
        $rows = Db::table('migration_id_mappings')
            ->where('entity', $entity)
            ->whereIn('contract_id', $scopes)
            ->pluck('new_id');

        $ids = [];
        foreach ($rows as $id) {
            if ($id !== null && $id !== '') {
                $ids[(string) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function cleanupMappings(array $scopes, bool $execute): void
    {
        $query = Db::table('migration_id_mappings')->whereIn('contract_id', $scopes);
        $affected = $execute ? (int) $query->delete() : (int) $query->count();

        $this->line(sprintf(
            'migration_id_mappings: %s=%d',
            $execute ? 'deleted' : 'would_delete',
            $affected
        ));
    }
}
