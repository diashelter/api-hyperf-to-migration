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
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;

use function Hyperf\Support\env;

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
     * Estratégia de geração de id no destino.
     */
    public function idStrategy(): string
    {
        return 'uuid7';
    }

    /**
     * Aplica mb_strtoupper aos campos string (regra herdada do push-mode).
     */
    public function normalizeStrings(): bool
    {
        return true;
    }

    /**
     * SQL de contagem para estimar o total de registros a migrar.
     * Retornar null desabilita o cálculo de `target` no entity_progress.
     * Deve retornar uma coluna chamada `count`.
     *
     * Exemplo: "SELECT COUNT(*) AS count FROM usuarios"
     */
    public function countSql(): ?string
    {
        return null;
    }

    /**
     * Executa countSql() e retorna o total, ou null se não implementado.
     */
    public function count(string $connection): ?int
    {
        $sql = $this->countSql();

        if ($sql === null) {
            return null;
        }

        $result = Db::connection($connection)->selectOne($sql);

        return $result ? (int) $result->count : null;
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
     * Se true, o EntityMigrator usa `ParallelInsertService::copyBatch()`
     * ao invés de `insertBatch()`. O serviço usa COPY nativo quando a extensão
     * `pgsql` existe e fallback bulk insert quando ela ainda não está carregada.
     * Recomendado apenas para tabelas de altíssimo volume (import_records, confrontation_records, rules).
     * Subclasses devem ler a flag `MIGRATION_USE_COPY` para permitir rollback fácil.
     */
    public function useCopy(): bool
    {
        return false;
    }

    protected function copyEnabled(bool $default = false): bool
    {
        return $this->envBool('MIGRATION_USE_COPY', $default);
    }

    protected function envBool(string $key, bool $default = false): bool
    {
        $value = env($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
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
            try {
                $logger = ApplicationContext::getContainer()
                    ->get(LoggerFactory::class)
                    ->get('migration-source');
                $logger->warning('Source sem placeholders keyset (:last_id/:limit)', [
                    'entity' => $this->entity(),
                    'class' => static::class,
                ]);
            } catch (\Throwable) {
                // logger opcional; não bloqueia paginação
            }
            if ($lastId !== null) {
                return [];
            }
            $rows = Db::connection($connection)->select($sql);
        }

        return array_map(static fn ($r) => (array) $r, $rows);
    }
}
