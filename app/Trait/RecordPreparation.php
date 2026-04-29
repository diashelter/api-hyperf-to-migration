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

namespace App\Trait;

use App\Service\IdMappingService;
use Ramsey\Uuid\Uuid;

/**
 * Lógica de preparação de registros (normalize/hash/UUID/FK resolution)
 * usada pelo `EntityMigrator` do modo pull.
 */
trait RecordPreparation
{
    /**
     * Aplica filterDuplicates contra `migration_id_mappings` por (entity, legacy_id, contract_id).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, string>}
     */
    protected function recordPrepFilterDuplicates(
        IdMappingService $idMappingService,
        string $entity,
        array $batch,
        string $contractId
    ): array {
        $legacyIds = array_values(array_unique(array_filter(array_map(
            static fn (array $record): ?string => isset($record['legacy_id']) ? (string) $record['legacy_id'] : null,
            $batch
        ))));

        if (empty($legacyIds)) {
            return [$batch, [], []];
        }

        $existing = $idMappingService->resolveMany($entity, $legacyIds, $contractId);
        $toInsert = [];
        $skipped = [];
        $existingMappings = [];

        foreach ($batch as $record) {
            $legacyId = $record['legacy_id'] ?? null;

            if ($legacyId !== null && isset($existing[$legacyId])) {
                $skipped[] = [
                    'legacy_id' => (string) $legacyId,
                    'new_id' => $existing[$legacyId],
                ];
                $existingMappings[(string) $legacyId] = $existing[$legacyId];
                continue;
            }

            $toInsert[] = $record;
        }

        return [$toInsert, $skipped, $existingMappings];
    }

    /**
     * Pré-aquece o cache do IdMappingService com `resolveMany` por entidade,
     * evitando query-por-registro em loops de FK resolution.
     *
     * @param array<string, string> $fkMap legacy_field => entity_name
     */
    protected function recordPrepPrefetchForeignKeys(
        IdMappingService $idMappingService,
        array $fkMap,
        array $batch,
        string $contractId
    ): void {
        if ($contractId !== '') {
            $idMappingService->prewarm('contracts', [$contractId], $contractId);
        }

        if (empty($fkMap) || empty($batch)) {
            return;
        }

        $legacyIdsByEntity = [];
        foreach ($fkMap as $entity) {
            $legacyIdsByEntity[$entity] = [];
        }

        foreach ($batch as $record) {
            foreach ($fkMap as $field => $entity) {
                if (! empty($record[$field])) {
                    $legacyIdsByEntity[$entity][] = (string) $record[$field];
                }
            }
        }

        foreach ($legacyIdsByEntity as $entity => $legacyIds) {
            if (empty($legacyIds)) {
                continue;
            }
            $idMappingService->prewarm($entity, array_values(array_unique($legacyIds)), $contractId);
        }
    }

    /**
     * Resolve campos legacy_<entity>_id para colunas <entity>_id usando o cache pré-aquecido.
     *
     * @param array<string, string> $fkMap
     */
    protected function recordPrepResolveForeignKeysFromMap(
        IdMappingService $idMappingService,
        array $fkMap,
        array $record,
        string $contractId
    ): array {
        foreach ($fkMap as $field => $entity) {
            if (empty($record[$field])) {
                unset($record[$field]);
                continue;
            }

            $targetField = $this->recordPrepFkTargetField($field);
            $resolved = $idMappingService->resolve($entity, (string) $record[$field], $contractId);

            if ($resolved !== null) {
                $record[$targetField] = $resolved;
            }

            unset($record[$field]);
        }

        return $record;
    }

    /**
     * Resolve contract_id: prioriza `legacy_contract_id` resolvido via mapping;
     * cai para o contract_id do header como tenant root.
     */
    protected function recordPrepResolveContractIdFK(
        IdMappingService $idMappingService,
        array $record,
        string $contractId
    ): array {
        if (! empty($record['legacy_contract_id'])) {
            $record['contract_id'] = $idMappingService->resolve('contracts', $record['legacy_contract_id'], $contractId)
                ?? $record['contract_id'] ?? null;
            unset($record['legacy_contract_id']);
        }

        if (empty($record['contract_id'])) {
            $resolved = $idMappingService->resolve('contracts', $contractId, $contractId);
            if ($resolved !== null) {
                $record['contract_id'] = $resolved;
            }
        }

        return $record;
    }

    /**
     * Pipeline padrão de preparação para insert.
     *
     * `resolveForeignKeysFn` deve aplicar o `fk_map` da entidade via
     * `recordPrepResolveForeignKeysFromMap`.
     *
     * @param callable(array, string): array $resolveForeignKeysFn
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>, 2: array<int, array<string, mixed>>}
     */
    protected function recordPrepPrepare(
        array $batch,
        string $contractId,
        bool $normalizeStrings,
        string $idStrategy,
        callable $resolveForeignKeysFn
    ): array {
        $now = date('Y-m-d H:i:s');
        $preparedBatch = [];
        $idMappings = [];
        $recordContexts = [];

        foreach ($batch as $record) {
            if ($normalizeStrings) {
                $record = $this->recordPrepNormalizeStrings($record);
            }

            $legacyId = $record['legacy_id'] ?? null;
            unset($record['legacy_id']);

            if (empty($record['id'])) {
                $record['id'] = $this->recordPrepGenerateId($idStrategy);
            }

            if (! empty($record['password'])) {
                $record['password'] = password_hash($record['password'], PASSWORD_BCRYPT);
            }

            $record['created_at'] = $record['created_at'] ?? $now;
            $record['updated_at'] = $record['updated_at'] ?? $now;

            $record = $resolveForeignKeysFn($record, $contractId);

            if ($legacyId !== null) {
                $idMappings[(string) $legacyId] = $record['id'];
            }

            $preparedBatch[] = $record;
            $recordContexts[] = [
                'legacy_id' => $legacyId !== null ? (string) $legacyId : null,
                'new_id' => $record['id'] ?? null,
            ];
        }

        return [$preparedBatch, $idMappings, $recordContexts];
    }

    protected function recordPrepNormalizeStrings(array $record): array
    {
        foreach (array_keys($record) as $field) {
            if (
                $field === 'password'
                || $field === 'contractor_type'
                || $field === 'tax_regime'
                || $field === 'format'
                || $field === 'movement_type'
                || $field === 'sector'
                || $field === 'origin'
                || $field === 'debit_credit'
                || $field === 'records_origin'
                || str_ends_with($field, '_id')
                || ! is_string($record[$field])
                || $field === 'status'
            ) {
                continue;
            }

            if ($field === 'email') {
                $record[$field] = strtolower($record[$field] ?? '');
                continue;
            }

            $record[$field] = mb_strtoupper((string) ($record[$field] ?? ''));
        }

        return $record;
    }

    protected function recordPrepGenerateId(string $strategy): string
    {
        return $strategy === 'uuid7'
            ? Uuid::uuid7()->toString()
            : Uuid::uuid4()->toString();
    }

    /**
     * Converte 'legacy_company_id' → 'company_id' e 'reference_layout' → 'reference_layout'
     * (campos sem prefixo `legacy_` mantêm o nome).
     */
    protected function recordPrepFkTargetField(string $legacyField): string
    {
        if (str_starts_with($legacyField, 'legacy_')) {
            return substr($legacyField, strlen('legacy_'));
        }

        return $legacyField;
    }
}
