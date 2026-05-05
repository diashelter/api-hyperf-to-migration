# Overview — conciliador-migrator

API HTTP de alta vazão construída em **Hyperf 3.1 / Swoole 5** (PHP 8.1+) com **PostgreSQL**. Hoje opera em **pull-mode**: recebe um `legacy_db`, cria um `migration_job` em fila Redis, e um worker lê o banco legado, transforma e insere no `conciliador_web`. Toda execução é idempotente via `migration_id_mappings`.

---

## Dois bancos — sempre seja explícito

| Connection | Env prefix | Finalidade |
|---|---|---|
| `default` | `DB_*` | Schema do próprio migrador: id_mappings, jobs, lookup_cache, auditoria |
| `conciliador_web` | `DB_WEB_*` | Banco de produção que recebe os dados migrados |

Existe ainda a connection dinâmica `legacy_database`, configurada em runtime por `LegacyConnectionFactory::connect($legacyDb)` para ler o banco de origem.

---

## Arquivos críticos

| Arquivo | Papel |
|---|---|
| `app/PullMode/Orchestrator/MigrationOrchestrator.php` | Coordena execução de um job: conecta no legado, percorre Sources, atualiza progresso |
| `app/PullMode/EntityMigrator.php` | Pipeline por entidade: pagina, transforma, deduplica, resolve FKs, insere |
| `app/PullMode/Source/*` | Definições de cada entidade: SQL legado, FKs, target table |
| `app/Service/EntityMetadataRegistry.php` | Lista ordenada de Sources ativas (ordem importa para FKs) |
| `app/Service/IdMappingService.php` | Lê/escreve `migration_id_mappings`; `resolve`, `resolveMany`, `storeBatch`, `prewarm` |
| `app/Service/ParallelInsertService.php` | Inserts no destino: `insertSync`, `insertBatch` (coroutines), `upsertBatch` |
| `app/Service/ExportLayoutSyncService.php` | Sincroniza `layouts_admin` antes de migrar `company_layout` |
| `app/Controller/Migration/MigrationJobController.php` | Endpoints HTTP do pull-mode |
| `app/Middleware/ApiTokenMiddleware.php` | Valida `X-Api-Key` |
| `app/Middleware/RateLimitMiddleware.php` | Rate limit por `legacy_db` em Redis |
| `config/autoload/databases.php` | Declara as três conexões (`default`, `conciliador_web`, `legacy_database`) |
| `migrations/` | Schema do migrador (nunca toca em `conciliador_web`) |

---

## Variáveis de ambiente

**Obrigatórias:**

```env
DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD                  # banco do migrador
DB_WEB_HOST / DB_WEB_DATABASE / DB_WEB_USERNAME / DB_WEB_PASSWORD  # banco destino
MIGRATION_API_KEY                                                  # auth da API
```

**Tuning:**

```env
MIGRATION_CHUNK_SIZE=1000        # registros por chunk de insert
MIGRATION_MAX_COROUTINES=5       # paralelismo Swoole
MIGRATION_RATE_LIMIT=60          # req/min padrão
MIGRATION_BULK_RATE_LIMIT=30     # req/min endpoints de alto volume
```

**Auditoria (legado, fluxo push-mode):**

```env
MIGRATION_AUDIT_ENABLED=true
MIGRATION_LOG_RECORDS=true
MIGRATION_SKIP_LOG_ENTITIES=import_records,confrontation_records,rules
```
