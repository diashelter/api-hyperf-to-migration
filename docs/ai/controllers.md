# Controllers — migration controller pattern

> Note: this pattern belongs to the legacy push-mode (POST per entity). The active flow today is pull-mode via `MigrationJobController`. The pattern below remains relevant when adding a sync/async per-entity endpoint or working with classes that still extend `AbstractMigrationController`.

---

## Base class

Every per-entity migration controller extends `AbstractMigrationController` and implements **exactly four** methods:

```php
protected function getTable(): string      // target DB table name
protected function getEntity(): string     // entity identifier (id_mappings + audit)
protected function getMaxBatchSize(): int  // 100 for sync-only, 2000 for async-capable
protected function getConnection(): string // almost always 'conciliador_web'
```

---

## Optional overrides

```php
// Per-field validation rules (Hyperf Validation syntax)
protected function validationRules(): array { ... }

// Resolve legacy foreign keys before insert
protected function resolveForeignKeys(array $record, string $contractId): array { ... }

// Override UUID strategy (e.g. UUID v7 for high-volume tables)
protected function generateId(): string { ... }
```

---

## `migrate()` template

The public `migrate()` method delegates entirely to `syncMigrate()` or `asyncMigrate()`:

```php
#[PostMapping(path: 'entities')]
public function migrate(): array
{
    return $this->syncMigrate();
}
```

**Do not** duplicate validation, deduplication, audit, or ID-mapping logic in `migrate()` — the parent handles all of it.

---

## Critical files

| File | Role |
|---|---|
| `app/Controller/AbstractMigrationController.php` | Base class: `syncMigrate`, `asyncMigrate`, `filterDuplicates`, `prepareRecordsForInsert` |
| `app/Controller/Migration/ContractUserMigrationController.php` | **Exception**: does not extend the base; handles pivot-table inserts with its own flow |
| `app/Controller/Migration/UserPermissionMigrationController.php` | **Exception**: performs DELETE instead of INSERT |

---

## Rule

**Audit, idempotency, and ID mapping are handled automatically** by `syncMigrate` / `asyncMigrate`. Don't duplicate that logic in subclasses.
