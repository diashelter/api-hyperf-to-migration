# Testing

**Framework**: PHPUnit 10.5 + Mockery 1.x.

**Run all tests**:

```bash
docker exec conciliador-migrator composer test       # full suite (co-phpunit, Swoole coroutines)
docker exec conciliador-migrator composer test:unit  # unit tests only
```

---

## Base class

Always extend `HyperfTest\UnitTestCase` for unit tests.

Key helpers:

```php
// Inject mocked dependencies via reflection (bypasses #[Inject])
$this->injectProperty($controller, 'insertService', Mockery::mock(ParallelInsertService::class));

// Set env vars with automatic tearDown restoration
$this->setEnvValue('MIGRATION_AUDIT_ENABLED', 'false');

// UUID validation
$this->assertMatchesRegularExpression(self::UUID_PATTERN, $result['id']);
```

---

## Conventions

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

- Class name: `{ClassName}Test`.
- Method name: `test{WhatItTests}` (descriptive, no abbreviations).
- Test classes are `final`.
- Always call `parent::setUp()` / `parent::tearDown()`.
- Mockery is closed automatically in `UnitTestCase::tearDown()`.

---

## E2E smoke tests

```bash
# Full migration flow with synthetic data
bash test/e2e/smoke-test.sh
bash test/e2e/smoke-test.sh --clean   # clean test data at the end

# Pull-mode end-to-end
LEGACY_DB=teste bash test/e2e/smoke-pull-mode.sh
MIGRATION_ENCRYPTED_API_KEY=v1... bash test/e2e/smoke-pull-mode.sh
```
