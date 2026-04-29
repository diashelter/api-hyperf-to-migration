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

namespace App\PullMode\Source;

use Hyperf\DbConnection\Db;

/**
 * Contrato base para "fontes legadas". Cada subclasse encapsula:
 *   - SQL bruto com aliases explícitos (legacy_id, legacy_<fk>_id, demais campos
 *     já com o nome esperado em conciliador_web);
 *   - Transformação não-trivial por linha (split de nome, defaults, parsing);
 *   - Regras de FK, validação e estratégia de UUID.
 *
 * O EntityMigrator delega leitura/transformação à Source e mantém o restante do
 * pipeline (filterDuplicates, prepareRecords, insert, storeBatch, audit) idêntico.
 *
 * Convenções importantes:
 *   - O SQL DEVE retornar a chave de paginação como `legacy_id` (ou outra coluna
 *     definida em paginationKey()). EntityMigrator usa esse valor como cursor.
 *   - O SQL DEVE conter os placeholders `:last_id` e `:limit` na cláusula de
 *     paginação keyset; em queries iniciais (sem lastId) o WHERE deve degradar
 *     graciosamente — a base trata isso com COALESCE/CASE.
 */
abstract class AbstractLegacySource
{
    /**
     * Identificador lógico usado em migration_id_mappings (ex.: "contracts").
     */
    abstract public function entity(): string;

    /**
     * Tabela de destino em conciliador_web (ex.: "contracts").
     */
    abstract public function targetTable(): string;

    /**
     * SQL bruto com aliases explícitos e cláusula de paginação keyset.
     *
     * Deve incluir `WHERE <pk> > :last_id ORDER BY <pk> ASC LIMIT :limit` (ou
     * equivalente). Para tabelas sem PK ordenável, sobrescrever paginate().
     */
    abstract public function sql(): string;

    /**
     * Tamanho do chunk de leitura. Tabelas com >1M registros costumam usar 2000.
     */
    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Coluna usada como cursor de keyset. Tem que ser um dos aliases retornados
     * pelo SELECT (default: "legacy_id").
     */
    public function paginationKey(): string
    {
        return 'legacy_id';
    }

    /**
     * Mapa de FKs legadas → entidade alvo no IdMappingService. O EntityMigrator
     * usa para prefetch e resolução. Default cobre o caso padrão de tenant.
     *
     * @return array<string, string>
     */
    public function fkMap(): array
    {
        return [
            'legacy_contract_id' => 'contracts',
        ];
    }

    /**
     * Regras de validação Hyperf por entidade. Vazio = sem validação.
     *
     * @return array<string, mixed>
     */
    public function validationRules(): array
    {
        return [];
    }

    /**
     * Estratégia de geração de id no destino. "uuid7" é recomendado para
     * tabelas de alto volume com índice ordenado (import_records,
     * confrontation_records).
     */
    public function idStrategy(): string
    {
        return 'uuid4';
    }

    /**
     * Aplica mb_strtoupper aos campos string (regra herdada do push-mode).
     */
    public function normalizeStrings(): bool
    {
        return true;
    }

    /**
     * Hook para entidades que não seguem o fluxo padrão (pivot tables, deletes).
     * Retornar uma string aciona EntityMigrator::runSpecialHandler().
     */
    public function specialHandler(): ?string
    {
        return null;
    }

    /**
     * Conexão de destino. Override apenas em casos raros.
     */
    public function targetConnection(): string
    {
        return 'conciliador_web';
    }

    /**
     * Se false, o pipeline NÃO tenta resolver/injetar contract_id no registro.
     * Usar em tabelas que não têm coluna contract_id (ex.: plan_items).
     */
    public function hasContractId(): bool
    {
        return true;
    }

    /**
     * Transformação por linha após o SELECT. O array já vem com chaves no formato
     * de destino (graças aos aliases do SQL); sobrescrever apenas para casos
     * não-triviais — concatenar campos, parsing de datas, defaults, etc.
     *
     * Não fazer FK resolution aqui: o pipeline cuida via fkMap().
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function transformRow(array $row, string $contractId): array
    {
        return $row;
    }

    /**
     * Lê o próximo chunk via keyset pagination. Usa o SQL da subclasse,
     * vinculando :last_id (string vazia na primeira chamada para satisfazer
     * comparação) e :limit.
     *
     * Subclasses raras (tabelas pivot ou sem coluna ordenável) podem sobrescrever
     * para implementar OFFSET-based pagination ou JOINs especiais.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paginate(string $connection, ?string $lastId, int $limit): array
    {
        $sql = $this->sql();

        $hasKeysetPlaceholders = str_contains($sql, ':last_id') && str_contains($sql, ':limit');

        if ($hasKeysetPlaceholders) {
            $rows = Db::connection($connection)->select($sql, [
                'last_id' => $lastId ?? '',
                'limit' => $limit,
            ]);
        } else {
            // Queries sem keyset pagination carregam tudo de uma vez.
            // Na retomada (lastId preenchido), retorna vazio — já processado.
            if ($lastId !== null) {
                return [];
            }
            $rows = Db::connection($connection)->select($sql);
        }

        return array_map(static fn ($r) => (array) $r, $rows);
    }
}
