# STYLEGUIDE.md — conciliador-migrator

## PHP Version & Tooling

- **PHP 8.1+** required
- `declare(strict_types=1)` is mandatory in every file — enforced by PHP-CS-Fixer
- Auto-format: `composer cs-fix` (PHP-CS-Fixer 3.x, config in `.php-cs-fixer.php`)
- Static analysis: `composer analyse` (PHPStan level 0, paths: `app/`, `config/`)

---

## Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Classes | PascalCase | `CompanyMigrationController`, `MigrationAuditService` |
| Methods / functions | camelCase | `syncMigrate()`, `filterDuplicates()`, `resolveContractIdFK()` |
| Variables | camelCase | `$contractId`, `$legacyId`, `$preparedBatch` |
| Database fields / columns | snake_case | `legacy_id`, `contract_id`, `created_at` |
| Database table names | plural snake_case | `companies`, `import_records`, `migration_id_mappings` |
| Route paths | kebab-case | `/api/v1/migration/import-records`, `/api/v1/migration/rules-sharings` |
| Environment variables | UPPER_SNAKE_CASE | `JWT_SECRET`, `MIGRATION_CHUNK_SIZE`, `DB_WEB_HOST` |
| PHP 8.1 enum values | lowercase string | `'completed'`, `'processing'`, `'received'` |
| Namespaces | PascalCase with backslash | `App\Controller\Migration`, `App\Service` |

---

## File Header

Every PHP file must start with the Hyperf license block followed by `declare(strict_types=1)`. `composer cs-fix` inserts this automatically.

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

## Dependency Injection

All dependencies are injected via `#[Inject]` attributes. Constructor injection is not used in controllers or services.

```php
use Hyperf\Di\Annotation\Inject;

class SomeController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ParallelInsertService $insertService;

    // Optional dependency: nullable with default null
    #[Inject]
    protected ?MigrationAuditService $auditService = null;
}
```

- Use nullable (`?Type = null`) for optional services that must not block operation if unavailable.
- Hyperf scans `app/` automatically; no manual registration needed.

---

## Controller Pattern

Every entity migration controller extends `AbstractMigrationController` and implements exactly four methods:

```php
protected function getTable(): string     // target DB table name
protected function getEntity(): string    // entity identifier (used in id_mappings + audit)
protected function getMaxBatchSize(): int // 100 for sync-only; 2000 for async-capable
protected function getConnection(): string // almost always 'conciliador_web'
```

Optional overrides:

```php
// Declare per-field validation rules (Hyperf Validation syntax)
protected function validationRules(): array { ... }

// Resolve legacy foreign keys before insert
protected function resolveForeignKeys(array $record, string $contractId): array { ... }

// Override UUID generation strategy (e.g. UUID v7 for high-volume entities)
protected function generateId(): string { ... }
```

The public `migrate()` method delegates entirely to `syncMigrate()` or `asyncMigrate()`:

```php
#[PostMapping(path: 'entities')]
public function migrate(): array
{
    return $this->syncMigrate();
}
```

Do not duplicate validation, deduplication, audit, or ID-mapping logic in `migrate()` — the parent handles all of it.

---

## Response Format

**Sync response** (returned directly):

```json
{
  "inserted": 10,
  "skipped": 2,
  "failed": 1,
  "errors": [
    {
      "index": 4,
      "legacy_id": "LEG-005",
      "validation_errors": { "code": ["The code field is required."] }
    }
  ],
  "id_mappings": {
    "LEG-001": "uuid-v4-...",
    "LEG-002": "uuid-v4-..."
  }
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

- Field names are always snake_case.
- `errors` is always an array (never null); empty array when no errors.
- `id_mappings` includes both newly inserted mappings and pre-existing mappings for skipped records.
- Do not add new top-level fields without updating `AsyncMigrationResponse` and `SyncMigrationResponse` Swagger schemas.

---

## Database Connection Usage

Always specify the connection explicitly:

```php
// Migrator's own tables (default database)
Db::connection('default')->table('migration_id_mappings')->...

// Target production tables
Db::connection('conciliador_web')->table('companies')->...
```

Model classes declare their connection via the Eloquent `$connection` property. Raw `Db::table(...)` without a connection defaults to `default` — this is almost never what you want for production data.

---

## String Normalization

Applied automatically in `prepareRecordsForInsert()` (only in sync mode by default):

| Field type | Transformation |
|---|---|
| General text fields | `mb_strtoupper()` |
| `email` field | `strtolower()` |
| `password` field | `password_hash($value, PASSWORD_BCRYPT)` |
| Fields ending in `_id` | No transformation |
| `contractor_type` | No transformation |
| Non-string values | Skipped |

Apply the same rules manually in `resolveForeignKeys()` when adding fields not covered by the automatic loop:

```php
// Correct: normalize a text field added in resolveForeignKeys
if (isset($record['city'])) {
    $record['city'] = mb_strtoupper((string) $record['city']);
}
```

---

## UUID Generation

```php
use Ramsey\Uuid\Uuid;

// Default: random UUID v4 (most entities)
$id = Uuid::uuid4()->toString();

// Time-ordered UUID v7 (high-volume entities like import_records)
// Overrides generateId() in the controller subclass:
protected function generateId(): string
{
    return Uuid::uuid7()->toString();
}
```

UUID v7 provides time-ordered primary keys that reduce B-tree index fragmentation for tables with millions of rows.

---

## Error Handling

- **Audit service failures** are caught silently with `error_log(...)` — audit must never block migration:
  ```php
  try {
      MigrationAuditLog::query()->create([...]);
  } catch (\Throwable $exception) {
      error_log(sprintf('[migration-audit] open failed: %s', $exception->getMessage()));
  }
  ```

- **Insert failures** are collected in `errors[]` and reflected in `failed` count — never throw an exception to the HTTP layer.
- **Validation failures** are per-record; invalid records are reported in `errors[]` without blocking valid records.
- **FK resolution failures**: if `idMappingService->resolve()` returns null, set the FK to null and let the DB constraint decide — do not silently discard the record.

---

## Testing

**Framework**: PHPUnit 10.5 + Mockery 1.x

**Run all tests**: `composer test` (co-phpunit for Swoole coroutine support)
**Run unit tests only**: `composer test:unit`

**Base class**: `HyperfTest\UnitTestCase` — always extend this for unit tests.

Key helpers:

```php
// Inject mocked dependencies using reflection (bypasses #[Inject])
$this->injectProperty($controller, 'insertService', Mockery::mock(ParallelInsertService::class));

// Set environment variables with automatic tearDown restoration
$this->setEnvValue('MIGRATION_AUDIT_ENABLED', 'false');

// UUID validation
$this->assertMatchesRegularExpression(self::UUID_PATTERN, $result['id']);
```

**Test class conventions**:

```php
#[CoversClass(CompanyMigrationController::class)]
final class CompanyMigrationControllerTest extends UnitTestCase
{
    private CompanyMigrationController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new CompanyMigrationController();
        $this->injectProperty($this->controller, 'request', Mockery::mock(RequestInterface::class));
        // inject other dependencies...
    }

    public function testMigrateInsertsValidRecords(): void { ... }
    public function testMigrateSkipsDuplicates(): void { ... }
    public function testMigrateReturnsValidationErrorsForInvalidRecords(): void { ... }
}
```

- Class names: `{ClassName}Test`
- Method names: `test{WhatItTests}` (descriptive, no abbreviations)
- Test classes are `final`
- Always call `parent::setUp()` / `parent::tearDown()`
- Mockery is closed automatically in `UnitTestCase::tearDown()`

---

## Migrations

- **File naming**: `YYYY_MM_DD_NNNNNN_verb_noun_table.php`
  - Example: `2026_04_06_000001_create_migration_audit_logs_table.php`
- **Database**: Always `default` (migrator's own schema); never modify `conciliador_web` schema
- **Primary key**: UUID string, non-incrementing:
  ```php
  $table->uuid('id')->primary();
  $table->boolean('incrementing')->default(false); // handled by Model
  ```
- **Timestamps**: Use `$table->timestamps()` for tables that track changes; omit `updated_at` for append-only tables (e.g., `migration_record_logs`)
- Write-once tables: set `const UPDATED_AT = null` in the Model

---

## Code Style Rules

Applied by `composer cs-fix` (`.php-cs-fixer.php`):

| Rule | Value |
|---|---|
| Array syntax | Short: `[]` not `array()` |
| String quotes | Single quotes preferred; double only when interpolating |
| Imports | Ordered: classes → functions → constants, alphabetically |
| Yoda conditions | Disabled: `$x === null` not `null === $x` |
| `declare(strict_types=1)` | Required in every file |
| Unused imports | Auto-removed |
| Phpdoc alignment | Left-aligned |
| `not` operator | `! $x` (with trailing space) not `!$x` |
| Else after return | Eliminated (`no_useless_else`) |
| Constants | Lowercase: `true`, `false`, `null` |
| Class element order | `ordered_class_elements` enforced |

**Imports**: Hyperf's helper functions (e.g., `env()`, `value()`) require explicit `use function` imports:

```php
use function Hyperf\Support\env;
```
