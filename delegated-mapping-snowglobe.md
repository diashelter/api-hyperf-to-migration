# Plano: Rastreabilidade, Auditoria e Idempotência na Migration API

## Context

O sistema `conciliador-migrator` é uma API HTTP (Hyperf/Swoole + PostgreSQL) que recebe batches de dados e os insere diretamente no banco `conciliador_web`. **Problemas críticos identificados:**

1. **Sem auditoria de requisições** — nenhum registro do que foi recebido, quando, por quem ou o resultado.
2. **Sem idempotência nos dados** — o mesmo `legacy_id` enviado duas vezes gera dois registros com UUIDs diferentes na tabela alvo, criando duplicidade em `conciliador_web`. Só os _mappings_ são deduplicados (upsert), não os dados em si.
3. **Inserts paralelos sem transação global** — no modo `asyncMigrate`, cada chunk é independente; falha parcial = dados inseridos parcialmente sem rollback.
4. **Mappings gravados após o insert** — se `storeBatch()` falhar, os dados ficam no banco sem mapeamento (registros órfãos).

**Objetivo:** Garantir integridade, velocidade e auditabilidade sem perda de informações, respeitando a arquitetura atual (Hyperf, Swoole coroutines, PostgreSQL).

---

## Arquivos Críticos

| Arquivo                                                          | Papel                                               |
| ---------------------------------------------------------------- | --------------------------------------------------- |
| `app/Controller/AbstractMigrationController.php`                 | Base de todos os flows (syncMigrate / asyncMigrate) |
| `app/Controller/Migration/ContractUserMigrationController.php`   | Exceção: não herda Abstract, tem lógica própria     |
| `app/Controller/Migration/UserPermissionMigrationController.php` | Exceção: operação DELETE, não INSERT                |
| `app/Service/ParallelInsertService.php`                          | Executa os INSERTs no banco                         |
| `app/Service/IdMappingService.php`                               | Guarda/resolve mappings legacy_id → uuid            |
| `app/Service/MigrationBatchService.php`                          | Gerencia status de batches async                    |
| `migrations/`                                                    | Migrations de infra (3 existentes)                  |

---

## Arquitetura da Solução

### Novas Tabelas (banco `default` — banco da migrator, não do conciliador_web)

#### Tabela 1: `migration_audit_logs`

Captura uma linha por **requisição HTTP recebida**.

```sql
id                  UUID PK
request_id          UUID UNIQUE (gerado no início do request)
contract_id         VARCHAR
entity              VARCHAR
batch_id            UUID NULL (FK migration_batches para async)
ip_address          VARCHAR NULL
user_agent          TEXT NULL
total_received      INT DEFAULT 0
total_valid         INT DEFAULT 0
total_invalid       INT DEFAULT 0
total_inserted      INT DEFAULT 0
total_failed        INT DEFAULT 0
total_skipped       INT DEFAULT 0   -- duplicados detectados e ignorados
status              VARCHAR         -- received | processing | completed | completed_with_errors | failed
request_payload     JSONB NULL      -- body completo recebido (para replay/auditoria)
response_payload    JSONB NULL      -- resposta enviada
validation_errors   JSONB NULL      -- erros de validação consolidados
insert_errors       JSONB NULL      -- erros de insert consolidados
started_at          TIMESTAMP
completed_at        TIMESTAMP NULL
processing_time_ms  INT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

**Index:** `(contract_id, entity, started_at DESC)`, `(request_id)`.

#### Tabela 2: `migration_record_logs`

Captura uma linha por **registro individual** processado. **Atenção:** para import_records (1M+), esta tabela cresce muito. Controlado por configuração (ver abaixo).

```sql
id              UUID PK
request_id      UUID (FK migration_audit_logs.request_id)
contract_id     VARCHAR
entity          VARCHAR
legacy_id       VARCHAR NULL
new_id          UUID NULL
status          VARCHAR  -- inserted | failed | validation_error | skipped_duplicate
error_message   TEXT NULL
created_at      TIMESTAMP
```

**Index:** `(request_id)`, `(contract_id, entity, legacy_id)`, `(status)`.

---

### Novo Service: `MigrationAuditService`

**Arquivo:** `app/Service/MigrationAuditService.php`

Responsabilidades:

- Criar registro de `migration_audit_logs` no início do request (`open()`)
- Atualizar ao final (`close()`)
- Inserir batch de `migration_record_logs` após os inserts principais
- Verificar se deve logar registros individuais (configurável por entidade)

```php
class MigrationAuditService {
    public function open(string $requestId, string $contractId, string $entity, array $rawBatch, string $ip, string $userAgent): void
    public function close(string $requestId, array $result, ?string $batchId = null): void
    public function logRecords(string $requestId, string $contractId, string $entity, array $recordLogs): void
    public function shouldLogRecords(string $entity): bool  // false para import_records por padrão
}
```

---

### Camada de Idempotência: `IdempotencyFilter`

**Implementado dentro de `AbstractMigrationController`** como método protegido.

**Lógica:**

```
Recebe: $batch (array de records com legacy_id) + $contractId + $entity
Faz: resolveMany() em migration_id_mappings para todos os legacy_ids do batch
Separa:
  - $toInsert   → legacy_ids sem mapping existente (novos)
  - $skipped    → legacy_ids que já têm mapping (já migrados, retornar mapping existente)
Retorna: [$toInsert, $skipped, $existingMappings]
```

Este check é um único `SELECT ... WHERE legacy_id IN (...)` — eficiente mesmo com batches grandes.

---

## Passo a Passo de Implementação

### Passo 1 — Migration: `migration_audit_logs`

**Arquivo:** `migrations/2026_04_06_000001_create_migration_audit_logs_table.php`

Criar tabela conforme schema acima no banco `default`.

### Passo 2 — Migration: `migration_record_logs`

**Arquivo:** `migrations/2026_04_06_000002_create_migration_record_logs_table.php`

Criar tabela conforme schema acima no banco `default`.

### Passo 3 — Models

- **`app/Model/MigrationAuditLog.php`** — model Hyperf para `migration_audit_logs`
- **`app/Model/MigrationRecordLog.php`** — model Hyperf para `migration_record_logs`

### Passo 4 — `MigrationAuditService`

**Arquivo:** `app/Service/MigrationAuditService.php`

Métodos:

- `open()`: INSERT em `migration_audit_logs` com status=`received`, salva request_payload
- `close()`: UPDATE com totais, response_payload, status final, processing_time_ms, completed_at
- `logRecords()`: INSERT batch em `migration_record_logs` via `ParallelInsertService.insertSync()` (banco `default`, não `conciliador_web`)
- `shouldLogRecords(entity)`: retorna `false` para entidades de alto volume (`import_records`, `confrontation_records`, `rules`) — configurável por `env('MIGRATION_LOG_RECORDS', true)`

### Passo 5 — Camada de idempotência em `AbstractMigrationController`

**Arquivo:** `app/Controller/AbstractMigrationController.php`

Adicionar método `filterDuplicates()`:

```php
protected function filterDuplicates(array $batch, string $contractId): array {
    // Extrair todos legacy_ids presentes no batch
    $legacyIds = array_filter(array_column($batch, 'legacy_id'));
    if (empty($legacyIds)) return [$batch, [], []];

    // Bulk check em migration_id_mappings
    $existing = $this->idMappingService->resolveMany($this->getEntity(), $legacyIds, $contractId);

    $toInsert = [];
    $skipped = [];
    $existingMappings = [];

    foreach ($batch as $record) {
        $lid = $record['legacy_id'] ?? null;
        if ($lid !== null && isset($existing[$lid])) {
            $skipped[] = ['legacy_id' => $lid, 'new_id' => $existing[$lid]];
            $existingMappings[$lid] = $existing[$lid];
        } else {
            $toInsert[] = $record;
        }
    }
    return [$toInsert, $skipped, $existingMappings];
}
```

### Passo 6 — Integrar em `syncMigrate()`

**Arquivo:** `app/Controller/AbstractMigrationController.php` — método `syncMigrate()`.

Fluxo modificado:

```
1. Gerar $requestId = Uuid::uuid4()
2. $startTime = microtime(true)
3. $rawBatch = $this->request->input('batch', [])
4. AuditService::open($requestId, $contractId, $entity, $rawBatch, $ip, $userAgent)
5. Validação (filterValidRecords) — mesmo de antes
6. [NOVO] filterDuplicates() — separa $toInsert vs $skipped
7. Insert apenas dos $toInsert
8. storeBatch() para ID mappings dos novos
9. Montar $result com: inserted, failed, skipped (novo campo), errors, id_mappings (novos + existingMappings)
10. AuditService::close($requestId, $result)
11. [CONDICIONAL] AuditService::logRecords() — batch com status de cada record
12. Retornar $result
```

### Passo 7 — Integrar em `asyncMigrate()`

**Arquivo:** `app/Controller/AbstractMigrationController.php` — método `asyncMigrate()`.

Mesmo fluxo do Passo 6, adaptado para async:

- `open()` antes de tudo
- `filterDuplicates()` antes do insert
- `close()` no `markCompleted()`
- `logRecords()` após o insert batch

### Passo 8 — `ContractUserMigrationController` (exceção)

**Arquivo:** `app/Controller/Migration/ContractUserMigrationController.php`

Não herda `AbstractMigrationController`, então a integração deve ser feita diretamente no método `migrate()`:

- Injetar `MigrationAuditService` via `#[Inject]`
- Adicionar `open()` no início e `close()` no final
- Para idempotência: pivot table `contract_user` não tem `legacy_id` próprio — verificar duplicidade por `(user_id, contract_id)` ao inserir (usar `INSERT ... ON CONFLICT DO NOTHING`)

### Passo 9 — `UserPermissionMigrationController` (exceção DELETE)

**Arquivo:** `app/Controller/Migration/UserPermissionMigrationController.php`

Opera com DELETE. Auditar:

- `total_received`: registros recebidos
- `total_inserted` → renomear semanticamente: usar `total_deleted` no log
- Não há idempotência necessária (DELETE é idempotente por natureza)

Integrar `MigrationAuditService.open()` e `close()` no fluxo.

---

## Fluxo Completo Após Implementação

```
POST /api/v1/migration/contracts
    │
    ├── ApiTokenMiddleware (JWT → contract_id, user_id)
    ├── RateLimitMiddleware (Redis)
    │
    └── Controller.migrate()
            │
            ├── 1. Gerar request_id (UUID)
            ├── 2. AuditService.open() → INSERT migration_audit_logs (status=received)
            ├── 3. filterValidRecords() → separa válidos/inválidos
            ├── 4. filterDuplicates() → separa novos/já migrados (1 query bulk)
            ├── 5. mutateFields() + resolveForeignKeys() nos registros novos
            ├── 6. insertSync() / insertBatch() → INSERT em conciliador_web
            ├── 7. idMappingService.storeBatch() → UPSERT migration_id_mappings
            ├── 8. AuditService.close() → UPDATE migration_audit_logs (totais, response, tempo)
            ├── 9. AuditService.logRecords() → INSERT batch migration_record_logs (se habilitado)
            └── 10. Response JSON
                    {
                      inserted: N,
                      skipped: M,   ← NOVO: duplicados detectados
                      failed: K,
                      errors: [...],
                      id_mappings: {...}
                    }
```

---

## Comportamento para Dado Reenviado

| Cenário                              | Antes (sem plano)                              | Depois (com plano)                                                |
| ------------------------------------ | ---------------------------------------------- | ----------------------------------------------------------------- |
| Mesmo `legacy_id` reenviado          | 2 registros em conciliador_web (DUPLICATA)     | 1º insert aceito, 2º retorna `skipped` com mapping existente      |
| Batch parcialmente falhou            | Registros inseridos ficam sem como identificar | `migration_record_logs` mostra quais foram `inserted` vs `failed` |
| Batch reenviado inteiro              | Todos duplicados                               | Registros já migrados → `skipped`; novos → `inserted`             |
| Erro de FK (legacy_id não resolvido) | Falha silenciosa ou DB constraint              | Registrado como `failed` com error_message no record_log          |

---

## Tratamento de Erros e Integridade

### Transação do audit log

O `migration_audit_logs` usa o banco `default` (separado do `conciliador_web`). Nunca bloqueia a migração. Se `open()` falhar: logar no stderr e continuar (auditoria não pode bloquear migração). Se `close()` falhar: idem.

### Dados parcialmente inseridos (async)

Após implementação, `migration_record_logs` registra o status de cada chunk. Status `failed` por chunk fica visível. O cliente pode reenviar apenas os `legacy_ids` que falharam (sistema vai deduplificar os que já foram inseridos).

### Orphaned data

Ao gravar o `migration_record_logs`, o `new_id` é gravado para cada record inserido. Mesmo que `storeBatch()` falhe, o audit log tem os `new_ids` gerados — recuperáveis.

---

## Configurações de Ambiente

```env
MIGRATION_AUDIT_ENABLED=true         # habilita auditoria (default: true)
MIGRATION_LOG_RECORDS=true           # log individual por record (default: true)
MIGRATION_LOG_RECORDS_PAYLOAD=false  # salva raw_data por record (default: false, alto volume)
MIGRATION_SKIP_LOG_ENTITIES=import_records,confrontation_records,rules
                                     # entidades excluídas do record-level log
```

---

## Verificação / Teste

1. **Teste de idempotência:** Enviar batch de contratos → anotar `legacy_id`. Reenviar mesmo batch → resposta deve ter `inserted: 0, skipped: N, failed: 0`.
2. **Teste de auditoria:** Após qualquer migração, consultar `SELECT * FROM migration_audit_logs ORDER BY started_at DESC LIMIT 1` — deve mostrar totais corretos e `request_payload` com o body recebido.
3. **Teste de erro parcial:** Enviar batch com alguns registros com FK inválida → verificar `migration_record_logs` com `status=failed` para os inválidos e `status=inserted` para os válidos.
4. **Teste de reenvio pós-erro:** Corrigir os registros com erro e reenviar o batch inteiro → os já migrados ficam `skipped`, os corrigidos ficam `inserted`.
5. **Teste de performance:** Enviar 2000 import_records → verificar que record-level log está desabilitado para esta entidade (por `MIGRATION_SKIP_LOG_ENTITIES`), mas `migration_audit_logs` tem o registro de totais.
