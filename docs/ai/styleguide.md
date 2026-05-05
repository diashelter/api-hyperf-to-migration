# Styleguide

## PHP & tooling

- **PHP 8.1+** required.
- `declare(strict_types=1)` mandatory in every file (enforced by PHP-CS-Fixer).
- Auto-format: `composer cs-fix` (config in `.php-cs-fixer.php`).
- Static analysis: `composer analyse` (PHPStan level 0, paths: `app/`, `config/`).

---

## Naming

| Element | Convention | Example |
|---|---|---|
| Classes | PascalCase | `CompanyMigrationController`, `MigrationAuditService` |
| Methods / functions | camelCase | `syncMigrate()`, `filterDuplicates()`, `resolveContractIdFK()` |
| Variables | camelCase | `$contractId`, `$legacyId`, `$preparedBatch` |
| DB columns | snake_case | `legacy_id`, `contract_id`, `created_at` |
| DB tables | plural snake_case | `companies`, `import_records`, `migration_id_mappings` |
| Routes | kebab-case | `/api/v1/migration/import-records` |
| Env vars | UPPER_SNAKE_CASE | `MIGRATION_API_KEY`, `DB_WEB_HOST` |
| PHP 8.1 enum values | lowercase | `'completed'`, `'processing'`, `'queued'` |
| Namespaces | PascalCase | `App\Controller\Migration`, `App\Service` |

---

## File header

Every PHP file starts with the Hyperf license block + `declare(strict_types=1)`. `composer cs-fix` inserts this automatically.

```php
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
```

---

## Dependency injection

All dependencies are injected via `#[Inject]`. **No constructor injection** in controllers/services.

```php
use Hyperf\Di\Annotation\Inject;

class SomeController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ParallelInsertService $insertService;

    #[Inject]
    protected ?MigrationAuditService $auditService = null;  // optional
}
```

- Use nullable (`?Type = null`) for optional services that must not block operation if unavailable.
- Hyperf scans `app/` automatically; no manual registration.

---

## Response format

**Sync response**:

```json
{
  "inserted": 10,
  "skipped": 2,
  "failed": 1,
  "errors": [
    { "index": 4, "legacy_id": "LEG-005", "validation_errors": { "code": ["The code field is required."] } }
  ],
  "id_mappings": { "LEG-001": "uuid-v4-...", "LEG-002": "uuid-v4-..." }
}
```

**Async response**:

```json
{
  "migration_batch_id": "uuid-v4-...",
  "entity": "import_records",
  "total_received": 1500,
  "status": "completed_with_errors",
  "inserted": 1490,
  "skipped": 3,
  "failed": 7,
  "errors": [...],
  "id_mappings": {...},
  "status_url": "/api/v1/migration/status/{batchId}"
}
```

- All field names are snake_case.
- `errors` is always an array (empty when no errors), never null.
- `id_mappings` includes both new and pre-existing mappings (for skipped records).
- New top-level fields require updating `AsyncMigrationResponse` / `SyncMigrationResponse` Swagger schemas.

---

## String normalization

Applied automatically in `prepareRecordsForInsert()` (sync mode by default):

| Field type | Transformation |
|---|---|
| General text fields | `mb_strtoupper()` |
| `email` | `strtolower()` |
| `password` | `password_hash($value, PASSWORD_BCRYPT)` |
| Fields ending in `_id` | No transformation |
| `contractor_type` | No transformation |
| Non-string values | Skipped |

Apply the same rules manually inside `resolveForeignKeys()` for fields not covered by the automatic loop:

```php
if (isset($record['city'])) {
    $record['city'] = mb_strtoupper((string) $record['city']);
}
```

---

## UUID generation

```php
use Ramsey\Uuid\Uuid;

// Default (most entities)
$id = Uuid::uuid4()->toString();

// Time-ordered, for high-volume entities
protected function generateId(): string
{
    return Uuid::uuid7()->toString();
}
```

UUID v7 reduces B-tree fragmentation on tables with millions of rows.

---

## Error handling

- **Audit failures** are silenced with `error_log(...)` — audit must never block migration:
  ```php
  try {
      MigrationAuditLog::query()->create([...]);
  } catch (\Throwable $e) {
      error_log(sprintf('[migration-audit] open failed: %s', $e->getMessage()));
  }
  ```
- **Insert failures** go into `errors[]` and `failed` count — never throw to HTTP layer.
- **Validation failures** are per-record; invalid records are reported in `errors[]` without blocking valid ones.
- **FK resolution failures**: if `idMappingService->resolve()` returns null, set the FK to null and let the DB constraint decide — do not silently drop the record.

---

## Code style rules (enforced by `composer cs-fix`)

| Rule | Value |
|---|---|
| Array syntax | Short: `[]` not `array()` |
| String quotes | Single preferred; double only when interpolating |
| Imports | Ordered: classes → functions → constants, alphabetically |
| Yoda conditions | Disabled: `$x === null`, not `null === $x` |
| `declare(strict_types=1)` | Required everywhere |
| Unused imports | Auto-removed |
| Phpdoc alignment | Left-aligned |
| `not` operator | `! $x` (with trailing space), not `!$x` |
| Else after return | Eliminated (`no_useless_else`) |
| Constants | Lowercase: `true`, `false`, `null` |
| Class element order | `ordered_class_elements` enforced |

**Hyperf helpers** require explicit `use function` imports:

```php
use function Hyperf\Support\env;
```

---

## See also

- `docs/ai/controllers.md` — controller pattern (`AbstractMigrationController` + 4 required methods)
- `docs/ai/database.md` — DB connections and migrations
- `docs/ai/testing.md` — PHPUnit + Mockery conventions
