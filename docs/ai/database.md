# Database — conexões e regras

## Três conexões

| Connection | Quando usar |
|---|---|
| `default` | Schema do migrador (`migration_jobs`, `migration_id_mappings`, `lookup_cache`, etc.) |
| `conciliador_web` | Banco de produção que recebe os dados migrados |
| `legacy_database` | Banco legado de leitura. Ativada em runtime por `LegacyConnectionFactory::connect($legacyDb)` |

Declaradas em `config/autoload/databases.php`.

---

## Sempre especifique a connection

```php
// Schema do migrador
Db::connection('default')->table('migration_id_mappings')->...

// Produção destino
Db::connection('conciliador_web')->table('companies')->...

// Legado (após connect)
Db::connection('legacy_database')->table('usuarios')->...
```

Models declaram a connection via propriedade `$connection`. Chamadas `Db::table(...)` **sem** connection caem em `default` — quase nunca é o que se quer para dados de produção.

---

## Regra `Do Not`

- **Nunca** use `Db::table(...)` sem especificar a connection — vai criar dados órfãos no banco errado.
- **Nunca** rode `storeBatch()` antes do insert ter sucesso — mappings sem dados criam FKs quebradas.

---

## Migrations

- Apenas para o schema do **próprio migrador** (`default`). **Nunca** modificar schema de `conciliador_web`.
- Nome do arquivo: `YYYY_MM_DD_NNNNNN_verb_noun_table.php`.
- Primary key sempre UUID:
  ```php
  $table->uuid('id')->primary();
  // No Model: $incrementing = false; $keyType = 'string';
  ```
- Use `$table->timestamps()` para tabelas que mudam; omita `updated_at` em tabelas append-only (ex.: `migration_record_logs`). Em tabelas write-once, defina `const UPDATED_AT = null` no Model.
- Aplicar migrations:
  ```bash
  docker exec conciliador-migrator php bin/hyperf.php migrate
  docker exec conciliador-migrator php bin/hyperf.php migrate:status
  ```

---

## Tabelas do schema `default`

| Tabela | Status | Finalidade |
|---|---|---|
| `migration_jobs` | Ativa | Estado do job pull-mode (entidade atual, progresso, totais, erros) |
| `migration_id_mappings` | Ativa | Mapeia `(entity, legacy_id, contract_id)` → `new_id`; base de idempotência e FK |
| `lookup_cache` | Ativa | Cache local de dados estáticos do destino: `roles`, `status`, `layouts_admin`, `permissions` |
| `migration_batches` | Legado | Sobrou do push-mode; `MigrationBatchService` não é chamado pelo fluxo atual |
| `migration_audit_logs` | Legado | Auditoria por request do push-mode; não é chamado pelo pull-mode |
| `migration_record_logs` | Legado | Logs por registro do push-mode; não são gerados pelo fluxo atual |
