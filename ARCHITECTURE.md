# ARCHITECTURE.md — conciliador-migrator

## System Purpose

`conciliador-migrator` is an HTTP API that transfers data from a legacy accounting system into the Conciliador Web production database (`conciliador_web`). It provides:

- **Batch ingestion** — JSON arrays of records via POST endpoints, one entity type per endpoint
- **Idempotency** — same `legacy_id` sent twice produces exactly one record; the second call returns the existing mapping
- **Audit trail** — every request and (optionally) every individual record is logged with timing, payload, and status
- **Incremental migration** — cross-entity foreign keys are resolved via a `legacy_id → new_uuid` mapping table; entities can be migrated in separate sessions and in any order within a dependency group

---

## High-Level Architecture

```
Client (legacy migration script)
        │
        │  POST /api/v1/migration/{entity}
        │  Headers: X-Api-Key: <encrypted-api-key>, X-Contract-Id: <contract>
        │  Body:    { "batch": [...] }
        ▼
┌─────────────────────────────────────────────────────┐
│                  Hyperf HTTP Server                  │
│                  (Swoole, port 9501)                 │
│                                                      │
│  ApiTokenMiddleware  ──── encrypted API key validation│
│         │                sets contract_id, user_id   │
│  RateLimitMiddleware ──── Redis per-contract counter │
│         │                                            │
│  Controller.migrate()                                │
│    │                                                 │
│    ├── AbstractMigrationController.syncMigrate()     │
│    │   or                                            │
│    └── AbstractMigrationController.asyncMigrate()    │
│             │                                        │
│    ┌────────┴────────┐                               │
│    │  Services layer  │                              │
│    │                  │                              │
│    │  MigrationAuditService   (open / close)         │
│    │  IdMappingService        (resolve / storeBatch) │
│    │  ParallelInsertService   (insertSync / Batch)   │
│    │  MigrationBatchService   (create / markXxx)     │
│    └────────┬────────┘                               │
└─────────────┼───────────────────────────────────────┘
              │
    ┌─────────┴──────────┐
    │                    │
    ▼                    ▼
┌──────────┐    ┌──────────────────┐
│ default  │    │ conciliador_web  │
│ (migrator│    │ (production DB)  │
│  schema) │    │                  │
│          │    │ companies        │
│ migration│    │ users            │
│ _id_map- │    │ imports          │
│ pings    │    │ import_records   │
│          │    │ contracts        │
│ migration│    │ plans            │
│ _batches │    │ rules            │
│          │    │ ... (20+ tables) │
│ migration│    └──────────────────┘
│ _audit_  │
│ logs     │
│          │
│ migration│
│ _record_ │
│ logs     │
└──────────┘
```

---

## Database Architecture

### `default` — Migrator's Own Schema

All tables here are created by the migrations in `migrations/`.

| Table | Purpose |
|---|---|
| `migration_id_mappings` | Maps `(entity, legacy_id, contract_id) → new_uuid`; the FK resolution backbone |
| `migration_batches` | Async batch lifecycle: `queued → processing → completed / completed_with_errors / failed` |
| `migration_audit_logs` | One row per HTTP request; captures IP, payload, totals, timing, and status |
| `migration_record_logs` | One row per individual record; status: `inserted / failed / skipped_duplicate / validation_error` |
| `lookup_cache` | Optional pre-built lookup table to speed up repeated FK resolutions |

### `conciliador_web` — Target Production Database

The migrator only performs `INSERT` (and in one case `DELETE`) into this database. It never creates or alters schemas there. Tables are pre-existing in the production system.

---

## Sync Request Lifecycle

```
POST /api/v1/migration/companies
  │
  ├─ 1. ApiTokenMiddleware: decrypt API key → set contract_id, user_id
  ├─ 2. RateLimitMiddleware: Redis counter, return 429 if exceeded
  │
  └─ CompanyMigrationController.migrate()
       │
       └─ AbstractMigrationController.syncMigrate()
            │
            ├─ 3.  Generate requestId (UUID v4)
            ├─ 4.  MigrationAuditService.open() → INSERT migration_audit_logs (status=received)
            ├─ 5.  filterValidRecords()  → Hyperf Validation per record; invalids → errors[]
            ├─ 6.  filterDuplicates()    → SELECT legacy_id IN (...) from migration_id_mappings
            │                             → split: $toInsert vs $skipped
            ├─ 7.  prepareRecordsForInsert()
            │       │ - remove legacy_id field
            │       │ - generate id (UUID v4)
            │       │ - normalize strings (mb_strtoupper, email lowercase)
            │       │ - hash passwords (PASSWORD_BCRYPT)
            │       │ - fill created_at / updated_at
            │       └─ resolveForeignKeys() → idMappingService.resolve() per FK
            │
            ├─ 8.  ParallelInsertService.insertSync()
            │       └─ Db::connection('conciliador_web')->table('companies')->insert(...)
            │          (wrapped in transaction; rollback on failure)
            │
            ├─ 9.  IdMappingService.storeBatch()
            │       └─ UPSERT migration_id_mappings (legacy_id → new_uuid)
            │
            ├─ 10. Build response: { inserted, skipped, failed, errors, id_mappings }
            ├─ 11. MigrationAuditService.close() → UPDATE migration_audit_logs (totals, timing)
            └─ 12. MigrationAuditService.logRecords() → INSERT migration_record_logs (if enabled)
```

---

## Async Batch Flow

Async mode (`asyncMigrate()`) is used for high-volume entities (import_records, rules, confrontation_records). The response returns immediately with a `migration_batch_id`; the actual inserts happen within the same request cycle using Swoole coroutines.

```
asyncMigrate()
  ├─ AuditService.open()
  ├─ filterValidRecords() + filterDuplicates()
  ├─ MigrationBatchService.create()    → status=queued
  ├─ MigrationBatchService.markProcessing()
  ├─ prepareRecordsForInsert()
  ├─ ParallelInsertService.insertBatch()
  │    └─ Splits into chunks (default: 1000 records/chunk)
  │    └─ Runs up to N chunks in parallel via Swoole Parallel (default: 5 coroutines)
  │    └─ Each chunk: Db::connection(...)→table(...)→insert(chunk)
  │    └─ Failed chunks reported individually by chunk_index
  ├─ IdMappingService.storeBatch()
  ├─ MigrationBatchService.markCompleted()  → status=completed|completed_with_errors
  ├─ AuditService.close()
  └─ AuditService.logRecords()

Response: { migration_batch_id, entity, status, inserted, failed, skipped, errors, id_mappings, status_url }
```

Client polls `GET /api/v1/migration/status/{batchId}` for progress.

---

## Services

| Service | Responsibility | DB connection |
|---|---|---|
| `ParallelInsertService` | `insertSync` (transaction), `insertBatch` (Swoole Parallel chunks), `upsertBatch` | configurable (caller decides) |
| `IdMappingService` | `resolve(entity, legacyId, contractId)`, `resolveMany(...)`, `storeBatch(...)` — in-memory cache per request | `default` |
| `MigrationBatchService` | CRUD for `migration_batches`; lifecycle methods | `default` |
| `MigrationAuditService` | `open()`, `close()`, `logRecords()`, `shouldLogRecords()` — non-fatal (try/catch) | `default` |
| `LookupCacheService` | Pre-built cache for frequent FK lookups, reduces repeat queries | `default` |
| `ApiKeyService` | AES-256-GCM decrypt/validate utilities used by `ApiTokenMiddleware` | — |

---

## Controller Hierarchy

```
AbstractMigrationController
│  (syncMigrate, asyncMigrate, filterDuplicates, prepareRecordsForInsert)
│
├── CompanyMigrationController          (sync,  conciliador_web)
├── UserMigrationController             (sync,  conciliador_web)
├── ContractMigrationController         (sync,  conciliador_web)
├── PlanMigrationController             (async, conciliador_web)
├── PlanItemMigrationController         (async, conciliador_web)
├── ImportMigrationController           (async, conciliador_web)
├── ImportRecordMigrationController     (async, conciliador_web)  ← UUID v7
├── LayoutMigrationController           (sync,  conciliador_web)
├── CompanyLayoutMigrationController    (sync,  conciliador_web)
├── RuleMigrationController             (async, conciliador_web)
├── RulesSharingMigrationController     (sync,  conciliador_web)
├── ConfrontationMigrationController    (async, conciliador_web)
├── ConfrontationRecordMigrationController (async, conciliador_web)
├── ImportSessionMigrationController    (sync,  conciliador_web)
├── PeopleMigrationController           (sync,  conciliador_web)
├── PeopleVinculatedMigrationController (sync,  conciliador_web)
└── ... (other entity controllers)

Exceptions (do NOT extend AbstractMigrationController):
├── ContractUserMigrationController     — pivot table; uses INSERT OR IGNORE; own audit integration
└── UserPermissionMigrationController   — DELETE operations; own audit integration
```

---

## ID Mapping System

`migration_id_mappings` stores `(entity, legacy_id, contract_id) → new_uuid`. This enables:

1. **Cross-entity FK resolution**: e.g., migrating a Company requires `contract_id` UUID; resolved via `idMappingService.resolve('contracts', legacyContractId, contractId)`
2. **Incremental migration**: entities migrated in separate sessions; IDs are always resolvable
3. **Idempotency**: `filterDuplicates()` bulk-checks all `legacy_ids` in a batch with a single `SELECT ... WHERE legacy_id IN (...)` query before any insert

`storeBatch()` uses UPSERT, so re-sending the same mapping is safe.

---

## Audit System

Two tables in the `default` database capture every migration event:

**`migration_audit_logs`** (one row per HTTP request):
- `request_id`, `contract_id`, `entity`, `ip_address`, `user_agent`
- `total_received`, `total_valid`, `total_invalid`, `total_inserted`, `total_failed`, `total_skipped`
- `status`: `received → processing → completed | completed_with_errors | failed`
- `request_payload` (JSONB), `response_payload` (JSONB), `validation_errors` (JSONB), `insert_errors` (JSONB)
- `started_at`, `completed_at`, `processing_time_ms`

**`migration_record_logs`** (one row per record, optional):
- `request_id`, `contract_id`, `entity`, `legacy_id`, `new_id`
- `status`: `inserted | failed | skipped_duplicate | validation_error`
- `error_message`

Record-level logging is disabled by default for high-volume entities via `MIGRATION_SKIP_LOG_ENTITIES`.

Audit is non-fatal: `open()` and `close()` wrap their DB calls in try/catch and log failures to stderr without interrupting the migration.

---

## Idempotency Guarantees

| Scenario | Behavior |
|---|---|
| Same `legacy_id` sent twice | 1st: `inserted`; 2nd: `skipped` with existing mapping returned |
| Entire batch resent | Already-migrated records → `skipped`; new records → `inserted` |
| Batch fails mid-insert | `migration_record_logs` shows per-chunk status; client resends failed `legacy_ids`; migrated ones are skipped |
| `legacy_id` absent | No deduplication; record is always inserted (only FK pivot tables fall into this case) |

---

## Security

- **Encrypted API key**: Every request requires `X-Api-Key` with an AES-256-GCM payload generated by the issuer system
- **Contract isolation**: API key `contract_id` payload must match `X-Contract-Id` header when both are present; mismatches return 403
- **Rate limiting**: Redis counter per `(contract_id, endpoint_type)` — 60 req/min standard, 30 req/min bulk endpoints (import-records, rules, confrontation-records)
- **No cross-contract data access**: `contract_id` is threaded through all queries as a filter

---

## UUID Strategy

| Entity | UUID version | Reason |
|---|---|---|
| Most entities | v4 (random) | `Uuid::uuid4()->toString()` — default in `generateId()` |
| `import_records` | v7 (time-ordered) | Millions of records; time-ordered UUIDs avoid B-tree index fragmentation |

Override `generateId()` in a subclass to use v7:

```php
protected function generateId(): string
{
    return Uuid::uuid7()->toString();
}
```
