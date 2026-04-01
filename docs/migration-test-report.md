# Relatório de Teste de Migração — Dados FAKE

**Data:** 2026-03-26
**Servidor:** http://localhost:9501
**X-Contract-Id (legado):** `CONTRATO-TESTE-001`
**JWT_SECRET:** `change-this-to-a-secure-random-string`

---

## Resumo Executivo

| Fase | Endpoint                                       | Tipo  | Status | inserted | failed |
| ---- | ---------------------------------------------- | ----- | ------ | -------- | ------ |
| 0    | `POST /api/v1/token`                           | Auth  | ✅ OK  | —        | —      |
| 1    | `POST /api/v1/migration/contracts`             | Sync  | ✅ OK  | 1        | 0      |
| 2a   | `POST /api/v1/migration/users`                 | Sync  | ✅ OK  | 1        | 0      |
| 2b   | `POST /api/v1/migration/contract-users`        | Sync  | ✅ OK  | 1        | 0      |
| 3a   | `POST /api/v1/migration/rules-sharings`        | Sync  | ✅ OK  | 1        | 0      |
| 3b   | `POST /api/v1/migration/plans`                 | Sync  | ✅ OK  | 1        | 0      |
| 3c   | `POST /api/v1/migration/peoples`               | Sync  | ✅ OK  | 1        | 0      |
| 3d   | `POST /api/v1/migration/plan-items`            | Sync  | ✅ OK  | 1        | 0      |
| 4    | `POST /api/v1/migration/companies`             | Sync  | ✅ OK  | 1        | 0      |
| 5a   | `POST /api/v1/migration/layouts`               | Sync  | ✅ OK  | 1        | 0      |
| 5b   | `POST /api/v1/migration/company-layouts`       | Sync  | ✅ OK  | 1        | 0      |
| 6    | `POST /api/v1/migration/people-vinculated`     | Sync  | ✅ OK  | 1        | 0      |
| 7    | `POST /api/v1/migration/rules`                 | Async | ✅ OK  | 1        | 0      |
| 8a   | `POST /api/v1/migration/imports`               | Sync  | ✅ OK  | 1        | 0      |
| 8b   | `POST /api/v1/migration/import-sessions`       | Sync  | ✅ OK  | 1        | 0      |
| 9a   | `POST /api/v1/migration/import-records`        | Async | ✅ OK  | 1        | 0      |
| 9b   | `POST /api/v1/migration/confrontations`        | Sync  | ✅ OK  | 1        | 0      |
| 9c   | `POST /api/v1/migration/confrontation-records` | Async | ✅ OK  | 1        | 0      |

**18 de 18 endpoints funcionando.**

---

## Mapeamentos de IDs gerados

| Legacy ID            | Entidade        | Novo UUID                              |
| -------------------- | --------------- | -------------------------------------- |
| `CONTRATO-TESTE-001` | contracts       | `f23ea2ed-e810-431c-a870-fafc9d080301` |
| `USR-CLEAN-001`      | users           | `3c20fb50-f23f-49fc-8556-a2733059ba46` |
| `RS-001`             | rules_sharings  | `f9ec7739-9d54-4aed-89a3-b01809ee7436` |
| `PLN-001`            | plans           | `72871cd5-21c8-47c2-8d68-c1ff61c08f6d` |
| `PEO-001`            | peoples         | `1c9f7cdb-3c0c-461f-89c1-af2fbb786649` |
| `PI-001`             | plan_items      | `db6ba8d3-bfb3-497e-b74a-58b6c201e2f0` |
| `EMP-001`            | companies       | `eb1753a7-4403-41b9-81d6-f84363468327` |
| `LAY-001`            | layouts         | `3f1e66d4-9048-4501-a48f-678a6d25f709` |
| `CL-001`             | company_layout  | `a8a84863-3629-430b-b4da-44c07e0cd184` |
| `RUL-001`            | rules           | `6abf89c3-6d48-4225-8d87-be5bb7837e16` |
| `IMP-001`            | imports         | `761444f1-fc76-4a56-b4a4-8ac5d05dff7c` |
| `IS-001`             | import_sessions | `dce88502-310e-48d1-b939-be99cf056666` |
| `CON-001`            | confrontations  | `5773bf11-32f6-414a-bcad-fa12e035be7e` |

---

## Bugs encontrados e correções aplicadas

### Bug 1 — `MigrationBatch.id` descartado por mass assignment

**Arquivo:** `app/Model/MigrationBatch.php`
**Problema:** O campo `id` não estava no array `$fillable`. O `MigrationBatchService::create()` passava `'id' => Uuid::uuid4()->toString()` mas o ORM descartava por mass assignment protection, resultando em `id = null` → NOT NULL constraint violation → HTTP 500 em todos os endpoints async.
**Correção:** Adicionado `'id'` ao `$fillable`.

### Bug 2 — `insertBatch` sempre usava a connection `default` (migrador)

**Arquivo:** `app/Service/ParallelInsertService.php` + `app/Controller/AbstractMigrationController.php`
**Problema:** `insertBatch()` não aceitava parâmetro de connection e chamava `Db::table($table)` que usa a connection `default` (banco do migrador), não `conciliador_web`. Todos os endpoints async (`rules`, `import-records`, `confrontation-records`) falhavam com "relation does not exist".
**Correção:** Adicionado parâmetro `string $connection = 'default'` ao `insertBatch()` e atualizado `asyncMigrate()` para passar `$this->getConnection()`.

### Bug 3 — `ContractUserMigrationController` usa connection `default`

**Arquivo:** `app/Controller/Migration/ContractUserMigrationController.php`
**Problema:** `Db::beginTransaction()` e `Db::table('contract_user')->insert()` não especificavam connection, usando a `default` (migrador). A tabela `contract_user` existe apenas em `conciliador_web`.
**Status:** ✅ Corrigido — `Db::connection('conciliador_web')` em `beginTransaction()`, `table()`, `commit()` e `rollBack()`.
**Correção adicional:** Ordem de validação vs. resolução de legacy FKs invertida — legacy FKs (`legacy_user_id`, `legacy_contract_id`, `legacy_role_id`) agora são resolvidos **antes** da validação de UUID, tornando o suporte a legacy IDs funcional.

### Bug 5 — `PlanMigrationController` sem `resolveContractIdFK`

**Arquivo:** `app/Controller/Migration/PlanMigrationController.php`
**Problema:** Igual aos 8 controllers da sessão anterior — não usava `resolveContractIdFK`, não tinha fallback para X-Contract-Id.
**Correção:** Substituído bloco duplicado por `return $this->resolveContractIdFK($record, $contractId)`.

---

## Detalhe por fase

### Fase 0 — Token

**Request:**

```bash
POST /api/v1/token
Content-Type: application/json

{
  "user_id": "USR-TESTE-001",
  "secret": "change-this-to-a-secure-random-string"
}
```

**Response:**

```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "type": "Bearer",
  "expires_in": 86400
}
```

---

### Fase 1 — Contracts (Sync) ✅

**Observação:** O `legacy_id` do contrato deve ser o mesmo valor do `X-Contract-Id`. Isso é fundamental para que o fallback de `resolveContractIdFK` funcione em todas as entidades filhas.

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "CONTRATO-TESTE-001",
      "cpf_cnpj": "12345678000195",
      "corporate_name": "Empresa Teste Migracao Ltda",
      "name": "Empresa Teste",
      "email": "migracaoteste@empresa.com",
      "phone": "11987654321",
      "contractor_type": "company",
      "company_count": 3,
      "user_count": 5,
      "street": "Rua das Flores",
      "number": "100",
      "city": "Sao Paulo",
      "state": "SP",
      "zipcode": "01310100"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "CONTRATO-TESTE-001": "f23ea2ed-e810-431c-a870-fafc9d080301" } }`

---

### Fase 2a — Users (Sync) ✅

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "USR-CLEAN-001",
      "name": "Joao Silva Teste",
      "email": "joao.clean2026@empresa.com",
      "password": "senha-fake-123"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "USR-CLEAN-001": "3c20fb50-f23f-49fc-8556-a2733059ba46" } }`

> **Nota:** Email deve ser único no banco. Em re-execuções, use um email diferente.

---

### Fase 2b — Contract-Users (Sync) ✅

**Request com legacy IDs:**

```json
{
  "batch": [
    {
      "legacy_user_id": "USR-CLEAN-001",
      "legacy_contract_id": "CONTRATO-TESTE-001",
      "role_id": "a150847e-dff6-4358-bbf5-8d4268ea94bd",
      "contract_admin": true
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": [] }`

> **Correções aplicadas:** (1) `Db::connection('conciliador_web')` em todas as queries; (2) resolução de legacy FKs movida para antes da validação.

---

### Fase 3a — Rules Sharings (Sync) ✅

**Teste de `resolveContractIdFK` — SEM `legacy_contract_id` no payload:**

**Request:**

```json
{
  "batch": [{ "legacy_id": "RS-001", "code": 1, "name": "Grupo Padrao" }]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "RS-001": "f9ec7739-9d54-4aed-89a3-b01809ee7436" } }`

✅ `contract_id` resolvido automaticamente via X-Contract-Id → `f23ea2ed-e810-431c-a870-fafc9d080301`

---

### Fase 3b — Plans (Sync) ✅

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "PLN-001",
      "name": "Plano de Contas 2024",
      "account_default": "1.1.01"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "PLN-001": "72871cd5-21c8-47c2-8d68-c1ff61c08f6d" } }`

---

### Fase 3c — Peoples (Sync) ✅

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "PEO-001",
      "corporate_name": "Maria Santos",
      "cpf_cnpj": "12345678901"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "PEO-001": "1c9f7cdb-3c0c-461f-89c1-af2fbb786649" } }`

---

### Fase 3d — Plan Items (Sync) ✅

**Campos corretos** (coluna `classification` não existe — usar `complete_account`/`reduced_account`):

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "PI-001",
      "name": "Caixa",
      "complete_account": "1.1.01.001",
      "reduced_account": "101",
      "legacy_plan_id": "PLN-001"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "PI-001": "db6ba8d3-bfb3-497e-b74a-58b6c201e2f0" } }`

---

### Fase 4 — Companies (Sync) ✅

**Request (sem `legacy_contract_id` — usa X-Contract-Id como fallback):**

```json
{
  "batch": [
    {
      "legacy_id": "EMP-001",
      "code": "EMP001",
      "cpf_cnpj": "98765432000188",
      "corporate_name": "Empresa Filial Ltda",
      "tax_regime": "Lucro Presumido",
      "city": "Campinas",
      "state": "SP",
      "email": "filial@empresa.com",
      "is_active": true,
      "legacy_plan_id": "PLN-001",
      "legacy_rules_sharing_id": "RS-001"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "EMP-001": "eb1753a7-4403-41b9-81d6-f84363468327" } }`

---

### Fase 5a — Layouts (Sync) ✅

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "LAY-001",
      "name": "Layout Extrato CSV",
      "format": "CSV",
      "movement_type": "Ambos",
      "start_row": 2,
      "date_column": "A",
      "history_column": "B",
      "debit_value_column": "C",
      "credit_value_column": "D"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "LAY-001": "3f1e66d4-9048-4501-a48f-678a6d25f709" } }`

---

### Fase 5b — Company Layouts (Sync) ✅

**Observações importantes:**

- `layout_exp` é NOT NULL e referencia a tabela `layouts_admin` (não `layouts`). O campo `legacy_layout_exp` resolve via entidade `layout_admins` no `migration_id_mappings`.
- Como `layouts_admin` não é gerenciada pelo migrador, passar o UUID diretamente como `layout_exp`.
- UUID exemplo de `layouts_admin`: `a1508716-4160-46d7-b310-447bd9b57340` (EXPORT)

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "CL-001",
      "legacy_company_id": "EMP-001",
      "legacy_layout_imp": "LAY-001",
      "layout_exp": "a1508716-4160-46d7-b310-447bd9b57340",
      "type_accounting": "DC",
      "credit_account": "1.1.01",
      "debit_account": "4.1.01"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "CL-001": "a8a84863-3629-430b-b4da-44c07e0cd184" } }`

---

### Fase 6 — People Vinculated (Sync) ✅

**Request:**

```json
{
  "batch": [{ "legacy_people_id": "PEO-001", "legacy_company_id": "EMP-001" }]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": [] }`

---

### Fase 7 — Rules (Async) ✅

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "RUL-001",
      "history": "Pagamento fornecedor",
      "client_supplier": "Fornecedor ABC",
      "debit_credit": "D",
      "id_history": "B",
      "id_debit": "C",
      "id_credit": "D",
      "exclusive": false,
      "sort_order": 1,
      "automatic_launch": true,
      "legacy_company_id": "EMP-001",
      "legacy_layout_id": "LAY-001"
    }
  ]
}
```

**Response:**

```json
{
  "migration_batch_id": "b522bdcc-2834-4a88-8ef9-04b5f15e01a4",
  "entity": "rules",
  "total_received": 1,
  "status": "completed",
  "inserted": 1,
  "failed": 0,
  "errors": [],
  "id_mappings": { "RUL-001": "6abf89c3-6d48-4225-8d87-be5bb7837e16" },
  "status_url": "/api/v1/migration/status/b522bdcc-2834-4a88-8ef9-04b5f15e01a4"
}
```

---

### Fase 8a — Imports (Sync) ✅

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "IMP-001",
      "name": "Extrato Jan/2026",
      "total_files": 1,
      "initial_period": "2026-01-01",
      "final_period": "2026-01-31",
      "previous_balance": 1500.0,
      "legacy_user_id": "USR-CLEAN-001",
      "legacy_company_id": "EMP-001",
      "legacy_company_layout_id": "CL-001"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "IMP-001": "761444f1-fc76-4a56-b4a4-8ac5d05dff7c" } }`

---

### Fase 8b — Import Sessions (Sync) ✅

**Campos obrigatórios:** `original_file_name` (além de `file_name`).

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "IS-001",
      "legacy_import_id": "IMP-001",
      "legacy_layout_id": "LAY-001",
      "file_name": "extrato_jan2026.csv",
      "original_file_name": "extrato_jan2026.csv",
      "size": 2048
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "IS-001": "dce88502-310e-48d1-b939-be99cf056666" } }`

---

### Fase 9a — Import Records (Async) ✅

**Observações:**

- `import-records` não gera `id_mappings` (retorna `[]`)
- Enviar `finalize: true` na última batch para atualizar status do `import_session`
- Transformações automáticas: `debit_value`/`credit_value` → `value` + `debit_credit`; `additional_information_1` → `additional_information`

**Request:**

```json
{
  "batch": [
    {
      "legacy_import_id": "IMP-001",
      "legacy_import_session_id": "IS-001",
      "date": "2026-01-10",
      "history": "Pagamento Fornecedor",
      "debit_value": 1500.0,
      "credit_value": 0,
      "num_doc": "NF-001",
      "additional_information_1": "Info adicional"
    }
  ],
  "finalize": true
}
```

**Response:**

```json
{
  "migration_batch_id": "f54f80c8-eac5-412f-9249-ecd7e10c06d4",
  "entity": "import_records",
  "total_received": 1,
  "status": "completed",
  "inserted": 1,
  "failed": 0,
  "errors": [],
  "id_mappings": [],
  "status_url": "/api/v1/migration/status/f54f80c8-eac5-412f-9249-ecd7e10c06d4"
}
```

---

### Fase 9b — Confrontations (Sync) ✅

**Request:**

```json
{
  "batch": [
    {
      "legacy_id": "CON-001",
      "description": "Conciliacao Jan/2026",
      "user_create_id": "3c20fb50-f23f-49fc-8556-a2733059ba46",
      "user_create": "Joao Silva Teste",
      "company_name": "Empresa Filial Ltda",
      "company_cnpj": "98765432000188",
      "consider_date": true,
      "consider_debit_credit": true,
      "consider_document": false,
      "ignore_equals": false,
      "legacy_company_id": "EMP-001"
    }
  ]
}
```

**Response:** `{ "inserted": 1, "failed": 0, "errors": [], "id_mappings": { "CON-001": "5773bf11-32f6-414a-bcad-fa12e035be7e" } }`

---

### Fase 9c — Confrontation Records (Async) ✅

**Campos que NÃO existem na tabela** (não enviar): `bank_value`, `financial_value`

**Request:**

```json
{
  "batch": [
    {
      "legacy_confrontation_id": "CON-001",
      "import_record_id": "019d2a71-ea15-72ff-9afe-43b998de55dd",
      "import_id": "761444f1-fc76-4a56-b4a4-8ac5d05dff7c",
      "date": "2026-01-10",
      "layout_code": "A",
      "debit_credit": "D",
      "value": 1500.0,
      "history": "Pagamento fornecedor",
      "records_origin": "B",
      "conciliated": true
    }
  ]
}
```

**Response:**

```json
{
  "migration_batch_id": "f023ec86-c42b-4a9f-8ed6-7bd5ee8fbf43",
  "entity": "confrontation_records",
  "status": "completed",
  "inserted": 1,
  "failed": 0,
  "errors": []
}
```

---

## Validação de FKs resolvidas automaticamente

A feature `resolveContractIdFK` foi validada com sucesso em todos os endpoints que possuem `contract_id` como FK:

| Controller                       | FK resolvida sem `legacy_contract_id` | Resultado |
| -------------------------------- | ------------------------------------- | --------- |
| RulesSharingMigrationController  | contract_id via X-Contract-Id         | ✅        |
| PlanMigrationController          | contract_id via X-Contract-Id         | ✅        |
| PeopleMigrationController        | contract_id via X-Contract-Id         | ✅        |
| CompanyMigrationController       | contract_id via X-Contract-Id         | ✅        |
| LayoutMigrationController        | contract_id via X-Contract-Id         | ✅        |
| RuleMigrationController          | contract_id via X-Contract-Id         | ✅        |
| ImportMigrationController        | contract_id via X-Contract-Id         | ✅        |
| ConfrontationMigrationController | contract_id via X-Contract-Id         | ✅        |

---

## Bugs pendentes (não corrigidos neste teste)

### 1. `company-layouts.layout_exp` — sem suporte a legacy FK

**Problema:** `legacy_layout_exp` resolve via entidade `layout_admins` (tabela `layouts_admin`), que não é gerenciada pelo migrador. Por isso, não é possível usar `legacy_layout_exp` — é necessário passar o UUID direto como `layout_exp`.

**Sugestão:** Documentar que `layout_exp` deve ser o UUID direto da tabela `layouts_admin`.

---

## Arquivos modificados durante o teste

| Arquivo                                                        | Motivo                                                                           |
| -------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| `app/Model/MigrationBatch.php`                                 | Adicionado `id` ao `$fillable`                                                   |
| `app/Service/ParallelInsertService.php`                        | Adicionado parâmetro `$connection` ao `insertBatch()`                            |
| `app/Controller/AbstractMigrationController.php`               | `asyncMigrate()` passa `$this->getConnection()` ao `insertBatch()`               |
| `app/Controller/Migration/PlanMigrationController.php`         | Usa `resolveContractIdFK` (igual aos outros 8 controllers)                       |
| `app/Controller/Migration/ContractUserMigrationController.php` | `Db::connection('conciliador_web')` + resolução de legacy FKs antes da validação |
