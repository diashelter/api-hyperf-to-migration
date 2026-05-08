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

namespace App\Service;

use Hyperf\DbConnection\Db;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Sincroniza layouts de exportação do banco legado com `layouts_admin` em
 * conciliador_web antes de cada migração, garantindo que todos os registros
 * referenciados em `layout_empresa.fk_layoutexp` existam no destino.
 *
 * Fluxo por migração:
 *   1. Coleta os `fk_layoutexp` distintos usados neste banco legado.
 *   2. Compara com `layouts_admin.code` — insere os ausentes.
 *   3. Garante que `migration_id_mappings` (entity=layouts_admin) exista para
 *      todos os códigos usados neste contrato, seja de entradas pré-existentes
 *      ou recém-inseridas.
 */
class ExportLayoutSyncService
{
    public function sync(string $legacyConnection, string $contractId): void
    {
        $usedCodes = $this->fetchUsedExpCodes($legacyConnection);

        if (empty($usedCodes)) {
            return;
        }

        $existing = $this->fetchExistingLayoutsAdmin($usedCodes);
        $missingCodes = array_diff($usedCodes, array_keys($existing));

        if (! empty($missingCodes)) {
            $newEntries = $this->insertMissingLayouts($legacyConnection, $missingCodes);
            $existing = array_replace($existing, $newEntries);
        }

        $this->ensureIdMappings($existing, $contractId);
    }

    /**
     * @return int[]
     */
    private function fetchUsedExpCodes(string $legacyConnection): array
    {
        $rows = Db::connection($legacyConnection)->select(
            'SELECT DISTINCT fk_layoutexp AS code
             FROM layout_empresa
             WHERE fk_layoutexp IS NOT NULL
               AND fk_layoutexp <> 0
               AND fk_empresa <> 0'
        );

        return array_map(static fn ($r) => (int) ((array) $r)['code'], $rows);
    }

    /**
     * @param  int[]                $codes
     * @return array<int, string>   code → uuid
     */
    private function fetchExistingLayoutsAdmin(array $codes): array
    {
        $rows = Db::connection('conciliador_web')
            ->table('layouts_admin')
            ->whereIn('code', $codes)
            ->get(['code', 'id']);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->code] = (string) $row->id;
        }

        return $result;
    }

    /**
     * Insere no `layouts_admin` os layouts EXP ausentes, lendo nome/formato
     * do banco legado.
     *
     * @param  int[]                $missingCodes
     * @return array<int, string>   code → novo uuid
     */
    private function insertMissingLayouts(string $legacyConnection, array $missingCodes): array
    {
        $inList = implode(',', array_map('intval', $missingCodes));

        $legacyRows = Db::connection($legacyConnection)->select(
            "SELECT pk, nome, formato FROM layout WHERE pk IN ({$inList}) AND tipo = 'EXP'"
        );

        $inserted = [];

        foreach ($legacyRows as $row) {
            $row = (array) $row;
            $newId = Uuid::uuid7()->toString();
            $format = strtoupper((string) ($row['formato'] ?? 'TEXTO'));
            $format = $format === '' ? 'TEXTO' : $format;

            try {
                Db::connection('conciliador_web')->table('layouts_admin')->insert([
                    'id'         => $newId,
                    'code'       => (int) $row['pk'],
                    'name'       => mb_strtoupper((string) ($row['nome'] ?? '')),
                    'format'     => substr($format, 0, 10),
                    'type'       => 'exp',
                    'active'     => true,
                    'validated'  => false,
                ]);

                $inserted[(int) $row['pk']] = $newId;
            } catch (Throwable) {
                // Se já existe (race condition ou retry), apenas ignora.
            }
        }

        return $inserted;
    }

    /**
     * Garante que cada code→uuid tenha um registro em `migration_id_mappings`
     * para este contractId. Usa INSERT … ON CONFLICT DO NOTHING para idempotência.
     *
     * @param array<int, string> $codeToUuid
     */
    private function ensureIdMappings(array $codeToUuid, string $contractId): void
    {
        foreach ($codeToUuid as $code => $uuid) {
            Db::connection('default')->statement(
                'INSERT INTO migration_id_mappings (id, entity, legacy_id, new_id, contract_id, created_at)
                 VALUES (:id, \'layouts_admin\', :legacy_id, :new_id, :contract_id, NOW())
                 ON CONFLICT ON CONSTRAINT uniq_entity_legacy_contract
                 DO UPDATE SET new_id = EXCLUDED.new_id',
                [
                    'id'          => Uuid::uuid7()->toString(),
                    'legacy_id'   => (string) $code,
                    'new_id'      => $uuid,
                    'contract_id' => $contractId,
                ]
            );
        }
    }
}
