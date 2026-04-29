# CLAUDE.md — conciliador-migrator

## Documentation

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — system design, request lifecycle, services, databases, idempotency, audit system, security
- **[STYLEGUIDE.md](STYLEGUIDE.md)** — naming conventions, DI pattern, controller template, response format, testing, code style

---

## Project Overview

`conciliador-migrator` is a high-throughput HTTP migration API built on **Hyperf 3.1 / Swoole 5** (PHP 8.1+) with **PostgreSQL**. It receives JSON batches from a legacy system and inserts records into the `conciliador_web` production database. Every request is audited and idempotent.

**Two databases — always be explicit:**

| Connection | Env prefix | Purpose |
|---|---|---|
| `default` | `DB_*` | Migrator's own schema: id mappings, batch tracking, audit logs |
| `conciliador_web` | `DB_WEB_*` | Target production database receiving migrated data |

---

## Commands

All commands run inside Docker containers. Never run `php`, `composer`, or `psql` directly on the host.

**Infrastructure:**

```bash
docker compose up -d                          # start all containers
docker compose down                           # stop all containers
docker compose logs -f migrator               # tail API logs
docker compose logs -f migrator-postgres      # tail DB logs
```

**Application (inside `conciliador-migrator`):**

```bash
docker exec conciliador-migrator composer test          # full test suite (co-phpunit)
docker exec conciliador-migrator composer test:unit     # PHPUnit unit tests only
docker exec conciliador-migrator composer cs-fix        # PHP-CS-Fixer auto-format
docker exec conciliador-migrator composer analyse       # PHPStan (level 0, 300M)
docker exec conciliador-migrator php bin/hyperf.php migrate  # run pending migrations
docker exec conciliador-migrator php bin/hyperf.php migrate:status  # check migration status
```

**Database (inside `conciliador-migrator-postgres`):**

```bash
# Banco do migrador (tracking, audit, id_mappings)
docker exec -it conciliador-migrator-postgres psql -U conciliador -d conciliador

# Queries úteis de auditoria
docker exec conciliador-migrator-postgres psql -U conciliador -d conciliador -c \
  "SELECT entity, status, total_inserted, total_failed, processing_time_ms FROM migration_audit_logs ORDER BY started_at DESC LIMIT 10;"

docker exec conciliador-migrator-postgres psql -U conciliador -d conciliador -c \
  "SELECT entity, legacy_id, new_id FROM migration_id_mappings WHERE contract_id='<tenant>' ORDER BY created_at DESC LIMIT 20;"
```

**E2E Smoke Test:**

```bash
# Testar o fluxo completo de migração com dados sintéticos
bash test/e2e/smoke-test.sh

# Testar e limpar dados de teste ao final
bash test/e2e/smoke-test.sh --clean

# Passar API key criptografada para o smoke pull-mode
MIGRATION_ENCRYPTED_API_KEY=v1... bash test/e2e/smoke-pull-mode.sh

# Pull-mode (lê banco legado, transforma e migra para conciliador_web)
LEGACY_DB=teste bash test/e2e/smoke-pull-mode.sh
```

**Pull-Mode Migration (modo PULL — em adição ao push existente):**

```bash
# 1. Configurar whitelist em .env (LEGACY_DBS=cliente_x,cliente_y + LEGACY_DB_<UPPER>_*)
# 2. Aplicar migration nova (cria migration_jobs)
docker exec conciliador-migrator php bin/hyperf.php migrate

# 3. Disparar (autenticado)
curl -X POST http://localhost:9501/api/v1/migration/database \
    -H "X-Api-Key: $API_KEY" \
    -H "X-Contract-Id: $CONTRACT_ID" \
    -d '{"legacy_db":"cliente_x"}'   # → 202 + job_id + status_url

# 4. Polling
curl http://localhost:9501/api/v1/migration/job/<job_id> \
    -H "X-Api-Key: $API_KEY" -H "X-Contract-Id: $CONTRACT_ID"
```

Veja `PLAN.md`, `TASKS.md`, `DONE.md` no root para detalhes da arquitetura pull-mode.

---

## Critical Files

| File | Role |
|---|---|
| `app/Controller/AbstractMigrationController.php` | Base for all migration flows (`syncMigrate` / `asyncMigrate`), `filterDuplicates`, `prepareRecordsForInsert` |
| `app/Controller/Migration/ContractUserMigrationController.php` | Exception: does not extend Abstract; handles pivot table inserts with its own flow |
| `app/Controller/Migration/UserPermissionMigrationController.php` | Exception: performs DELETE operations instead of INSERT |
| `app/Service/ParallelInsertService.php` | Low-level DB inserts; `insertSync` (transactional), `insertBatch` (Swoole Parallel coroutines), `upsertBatch` |
| `app/Service/IdMappingService.php` | Reads/writes `migration_id_mappings`; `resolve`, `resolveMany`, `storeBatch` |
| `app/Service/MigrationBatchService.php` | Lifecycle of `migration_batches` (create → markProcessing → markCompleted/markFailed) |
| `app/Service/MigrationAuditService.php` | Request-level audit (`open`/`close`) and optional record-level logging (`logRecords`) |
| `app/Middleware/ApiTokenMiddleware.php` | Encrypted API key validation; sets `contract_id` and `user_id` request attributes |
| `app/Middleware/RateLimitMiddleware.php` | Redis-backed per-contract rate limiting |
| `config/autoload/databases.php` | Declares both DB connections |
| `migrations/` | 5 migrations for migrator's own schema (never touches `conciliador_web` schema) |

---

## Adding a New Entity Controller

Extend `AbstractMigrationController`, implement the four required methods, and delegate `migrate()` to `syncMigrate()` or `asyncMigrate()`. See [STYLEGUIDE.md — Controller Pattern](STYLEGUIDE.md#controller-pattern) for the full template and optional overrides.

**Audit, idempotency, and ID mapping are handled automatically by `syncMigrate`/`asyncMigrate` — do not duplicate that logic.**

---

## Environment Variables

**Required:**

```env
DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD        # migrator's own DB
DB_WEB_HOST / DB_WEB_DATABASE / DB_WEB_USERNAME / DB_WEB_PASSWORD  # target DB
MIGRATION_API_KEY / MIGRATION_API_KEY_ENCRYPTION_KEY    # API key auth
```

**Tuning:**

```env
MIGRATION_CHUNK_SIZE=1000        # records per DB insert chunk (async mode)
MIGRATION_MAX_COROUTINES=5       # max parallel Swoole coroutines (async)
MIGRATION_RATE_LIMIT=60          # req/min for standard endpoints
MIGRATION_BULK_RATE_LIMIT=30     # req/min for high-volume endpoints
```

**Audit:**

```env
MIGRATION_AUDIT_ENABLED=true
MIGRATION_LOG_RECORDS=true
MIGRATION_SKIP_LOG_ENTITIES=import_records,confrontation_records,rules
```

---

## Rules — Do Not

- **Never** use `Db::table(...)` without specifying the connection — omitting it hits `default` and creates orphaned data in the wrong database.
- **Never** call `insertService->insertSync/insertBatch` without first running `filterDuplicates()` — callers outside `AbstractMigrationController` must implement their own duplicate check.
- **Never** add record-level logging for `import_records`, `confrontation_records`, or `rules` — these tables receive millions of records and will overflow `migration_record_logs`.
- **Never** run `storeBatch()` before the insert succeeds — mappings without data create broken FK references in future migrations.
- **Never** use constructor injection in controllers — use `#[Inject]` attributes only (see [STYLEGUIDE.md](STYLEGUIDE.md#dependency-injection)).
