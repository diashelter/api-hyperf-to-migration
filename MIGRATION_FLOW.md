# Guia de Migração e Inserção em Lote — Conciliador Web

> **Objetivo:** Inserir dados de um sistema externo nas tabelas do Conciliador Web, respeitando a ordem de dependências, constraints e regras de negócio do sistema, como se os registros tivessem sido criados nativamente.

---

## Índice

1. [Diagrama de Dependências](#diagrama-de-dependências)
2. [Ordem de Inserção](#ordem-de-inserção)
3. [Fluxo 1 — Contrato](#fluxo-1--contrato)
4. [Fluxo 2 — Usuário e Vínculo](#fluxo-2--usuário-e-vínculo)
5. [Fluxo 3 — RulesSharing (Opcional)](#fluxo-3--rulessharing-opcional)
6. [Fluxo 4 — Plano de Contas](#fluxo-4--plano-de-contas)
7. [Fluxo 5 — Layout de Importação](#fluxo-5--layout-de-importação)
8. [Fluxo 6 — Empresa](#fluxo-6--empresa)
9. [Fluxo 7 — Vínculo Empresa × Layout](#fluxo-7--vínculo-empresa--layout)
10. [Fluxo 8 — Participantes](#fluxo-8--participantes)
11. [Fluxo 9 — Importação de Dados Transacionais](#fluxo-9--importação-de-dados-transacionais)
12. [Fluxo 10 — Regras Contábeis](#fluxo-10--regras-contábeis)
13. [Fluxo 11 — Conciliação](#fluxo-11--conciliação)
14. [Fluxo 12 — Exportação](#fluxo-12--exportação)
15. [Armadilhas e Pontos Críticos](#armadilhas-e-pontos-críticos)
16. [Resumo dos FormRequests](#resumo-dos-formrequests)

---

## Diagrama de Dependências

```
status (lookup pré-existente)
   │
contracts ────────────────────────────────────────────────────────┐
   ├── rules_sharings                                              │
   ├── plans                                                       │
   │     └── plan_items                                           │
   ├── layouts                                                     │
   ├── users ──────────────────────────────────────────────────────┤
   │     └── contract_user (pivot: users + contracts + roles)     │
   └── companies ◄─────────── (plan_id?, rules_sharing_id?)       │
         ├── company_layout (pivot: companies + layouts) ──────────┤
         ├── peoples                                               │
         │     └── people_vinculated                              │
         └── imports ◄──── (company_layout_id)                    │
               └── import_sessions                                │
                     └── import_records                           │
                           └── rules (aplicadas post-insert)      │
                                 └── confrontations               │
                                       └── confrontation_records  │
                                             └── exports ─────────┘
```

---

## Ordem de Inserção

| #   | Tabela                  | Depende de                                   | Obrigatório                             |
| --- | ----------------------- | -------------------------------------------- | --------------------------------------- |
| 1   | `contracts`             | `status` (lookup)                            | Sim — raiz do tenant                    |
| 2   | `users`                 | —                                            | Sim — para autoria dos registros        |
| 3   | `contract_user`         | `contracts`, `users`, `roles`                | Sim — acesso ao sistema                 |
| 4   | `rules_sharings`        | `contracts`                                  | Não — só com compartilhamento de regras |
| 5   | `plans`                 | `contracts`                                  | Não — só com plano de contas            |
| 6   | `plan_items`            | `plans`                                      | Não — contas do plano                   |
| 7   | `layouts`               | `contracts`                                  | Sim — necessário para importar          |
| 8   | `companies`             | `contracts`, `plans`?, `rules_sharings`?     | Sim                                     |
| 9   | `company_layout`        | `companies`, `layouts`                       | **Crítico** — sem ele não há importação |
| 10  | `peoples`               | `contracts`                                  | Não — cadastro de participantes         |
| 11  | `people_vinculated`     | `peoples`, `companies`/`rules_sharings`      | Não                                     |
| 12  | `imports`               | `companies`, `company_layout`, `contracts`   | Sim                                     |
| 13  | `import_sessions`       | `imports`, `layouts`                         | Sim — uma por arquivo/lote              |
| 14  | `import_records`        | `imports`, `import_sessions`                 | Sim — registros transacionais           |
| 15  | `rules`                 | `companies`, `layouts`, `contracts`          | Não — regras contábeis                  |
| 16  | `confrontations`        | `companies`, `contracts`                     | Não — conciliação banco × financeiro    |
| 17  | `confrontation_records` | `confrontations`, `import_records`           | Não                                     |
| 18  | `exports`               | `imports`, `companies`, `contracts`, `users` | Não                                     |

---

## Fluxo 1 — Contrato

O contrato é a **raiz do tenant**. Todos os outros registros pertencem a um contrato.

### FormRequest: `StoreContractRequest`

```php
'cpf_cnpj'           => 'required|string|size:14',        // apenas dígitos
'corporate_name'     => 'required|string|max:255',
'name'               => 'required|string|max:255',         // nome fantasia
'email'              => 'nullable|email|max:255',
'phone'              => 'nullable|string|max:15',
'contractor_type'    => 'required|in:individual,company',
'company_count'      => 'required|integer|min:1',
'user_count'         => 'nullable|integer|min:1',
'street'             => 'nullable|string|max:255',
'number'             => 'nullable|string|max:50',
'neighborhood'       => 'nullable|string|max:100',
'city'               => 'nullable|string|max:100',
'complement'         => 'nullable|string',
'state'              => 'nullable|string|size:2',
'zipcode'            => 'nullable|string|max:10',
'activity_branch'    => 'nullable|string',
'is_approval'        => 'nullable|boolean',
'status_contract'    => 'nullable|uuid|exists:status,id',
'legacy_database_id' => 'nullable|string|max:100',
```

### Exemplo de Payload JSON

```json
{
  "cpf_cnpj": "12345678000195",
  "corporate_name": "Escritório Contábil Silva & Associados Ltda",
  "name": "Silva Contabilidade",
  "email": "contato@silvacontabil.com.br",
  "phone": "11987654321",
  "contractor_type": "company",
  "company_count": 10,
  "user_count": 5,
  "street": "Rua das Palmeiras",
  "number": "142",
  "neighborhood": "Centro",
  "city": "São Paulo",
  "state": "SP",
  "zipcode": "01310100",
  "activity_branch": "Serviços Contábeis",
  "is_approval": false,
  "legacy_database_id": "CONT-001"
}
```

### Como fica no banco (`contracts`)

| campo              | valor                                         |
| ------------------ | --------------------------------------------- |
| id                 | `a1b2c3d4-e5f6-7890-abcd-ef1234567890`        |
| cpf_cnpj           | `12345678000195`                              |
| corporate_name     | `Escritório Contábil Silva & Associados Ltda` |
| name               | `Silva Contabilidade`                         |
| email              | `contato@silvacontabil.com.br`                |
| phone              | `11987654321`                                 |
| contractor_type    | `company`                                     |
| company_count      | `10`                                          |
| user_count         | `5`                                           |
| street             | `Rua das Palmeiras`                           |
| number             | `142`                                         |
| neighborhood       | `Centro`                                      |
| city               | `São Paulo`                                   |
| state              | `SP`                                          |
| zipcode            | `01310100`                                    |
| activity_branch    | `Serviços Contábeis`                          |
| is_approval        | `false`                                       |
| status_contract    | `null`                                        |
| legacy_database_id | `CONT-001`                                    |
| created_at         | `2025-03-23 10:00:00`                         |
| updated_at         | `2025-03-23 10:00:00`                         |

---

## Fluxo 2 — Usuário e Vínculo

### 2a. FormRequest: `StoreUserRequest`

```php
'name'        => 'required|string|max:255',
'email'       => 'required|email|unique:users,email',
'password'    => 'required|string|min:8',   // será convertido para bcrypt hash
'is_admin'    => 'nullable|boolean',
'is_internal' => 'nullable|boolean',
'status'      => 'nullable|boolean',
```

### Exemplo de Payload JSON

```json
{
  "name": "Ana Paula Ferreira",
  "email": "ana.ferreira@silvacontabil.com.br",
  "password": "Senha@2025",
  "is_admin": false,
  "is_internal": false,
  "status": true
}
```

### Como fica no banco (`users`)

| campo                | valor                                            |
| -------------------- | ------------------------------------------------ |
| id                   | `b2c3d4e5-f6a7-8901-bcde-f12345678901`           |
| name                 | `Ana Paula Ferreira`                             |
| email                | `ana.ferreira@silvacontabil.com.br`              |
| password             | `$2y$12$aB3cD4eF5gH6iJ7kL8mN9O...` (bcrypt hash) |
| status               | `true`                                           |
| is_admin             | `false`                                          |
| is_internal          | `false`                                          |
| is_support           | `false`                                          |
| must_change_password | `false`                                          |
| temporary_password   | `null`                                           |
| password_changed_at  | `null`                                           |
| email_verified_at    | `null`                                           |
| created_at           | `2025-03-23 10:01:00`                            |
| updated_at           | `2025-03-23 10:01:00`                            |

### 2b. FormRequest: `AttachUserToContractRequest`

```php
'user_id'       => 'required|uuid|exists:users,id',
'contract_id'   => 'required|uuid|exists:contracts,id',
'role_id'       => 'required|uuid|exists:roles,id',  // consultar roles existentes
'contract_admin'=> 'nullable|boolean',
```

> **Nota:** Consultar roles com `SELECT id, name FROM roles` antes de vincular.

### Exemplo de Payload JSON

```json
{
  "user_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "role_id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
  "contract_admin": true
}
```

### Como fica no banco (`contract_user`)

> Esta tabela **não possui timestamps nem id próprio**.

| user_id        | contract_id    | role_id        | contract_admin |
| -------------- | -------------- | -------------- | -------------- |
| `b2c3d4e5-...` | `a1b2c3d4-...` | `c3d4e5f6-...` | `true`         |

---

## Fluxo 3 — RulesSharing (Opcional)

Usar apenas quando empresas do contrato precisam **compartilhar as mesmas regras contábeis**.

### FormRequest: `StoreRulesSharingRequest`

```php
'contract_id' => 'required|uuid|exists:contracts,id',
'code'        => 'required|integer|unique:rules_sharings,code',
'name'        => 'required|string|max:30|unique:rules_sharings,name',
```

### Exemplo de Payload JSON

```json
{
  "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "code": 1001,
  "name": "Regras Gerais SP"
}
```

### Como fica no banco (`rules_sharings`)

| campo       | valor                                  |
| ----------- | -------------------------------------- |
| id          | `d4e5f6a7-b8c9-0123-defa-234567890123` |
| contract_id | `a1b2c3d4-...`                         |
| code        | `1001`                                 |
| name        | `Regras Gerais SP`                     |
| created_at  | `2025-03-23 10:02:00`                  |
| updated_at  | `2025-03-23 10:02:00`                  |

---

## Fluxo 4 — Plano de Contas

### 4a. FormRequest: `StorePlanRequest`

```php
'contract_id'     => 'required|uuid|exists:contracts,id',
'name'            => 'required|string|max:70|unique:plans,name',
'account_default' => 'nullable|string|max:50',
'code'            => 'nullable|string',
```

### Exemplo de Payload JSON

```json
{
  "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "name": "Plano de Contas 2025",
  "account_default": "1.1.01",
  "code": "PCG-2025"
}
```

### Como fica no banco (`plans`)

| campo           | valor                                  |
| --------------- | -------------------------------------- |
| id              | `e5f6a7b8-c9d0-1234-efab-345678901234` |
| contract_id     | `a1b2c3d4-...`                         |
| name            | `Plano de Contas 2025`                 |
| account_default | `1.1.01`                               |
| code            | `PCG-2025`                             |
| created_at      | `2025-03-23 10:03:00`                  |
| updated_at      | `2025-03-23 10:03:00`                  |

### 4b. FormRequest: `StorePlanItemRequest`

```php
'plan_id'          => 'required|uuid|exists:plans,id',
'name'             => 'required|string|max:70',
'complete_account' => 'nullable|string|max:20',
'reduced_account'  => 'nullable|string|max:20',
'type'             => 'nullable|string|max:50',
'origin'           => 'nullable|in:C,D,I',  // C=Crédito D=Débito I=Indiferente
```

### Exemplo de Payload JSON (lote)

```json
[
  {
    "plan_id": "e5f6a7b8-c9d0-1234-efab-345678901234",
    "name": "Caixa e Equivalentes",
    "complete_account": "1.1.01.001",
    "reduced_account": "1101001",
    "type": "Ativo Circulante",
    "origin": "D"
  },
  {
    "plan_id": "e5f6a7b8-c9d0-1234-efab-345678901234",
    "name": "Receitas de Serviços",
    "complete_account": "3.1.01.001",
    "reduced_account": "3101001",
    "type": "Receita Operacional",
    "origin": "C"
  },
  {
    "plan_id": "e5f6a7b8-c9d0-1234-efab-345678901234",
    "name": "Fornecedores a Pagar",
    "complete_account": "2.1.02.001",
    "reduced_account": "2102001",
    "type": "Passivo Circulante",
    "origin": "C"
  }
]
```

### Como fica no banco (`plan_items`)

| id         | plan_id    | name                 | complete_account | reduced_account | type                | origin |
| ---------- | ---------- | -------------------- | ---------------- | --------------- | ------------------- | ------ |
| `f6a7-...` | `e5f6-...` | Caixa e Equivalentes | `1.1.01.001`     | `1101001`       | Ativo Circulante    | `D`    |
| `a7b8-...` | `e5f6-...` | Receitas de Serviços | `3.1.01.001`     | `3101001`       | Receita Operacional | `C`    |
| `b8c9-...` | `e5f6-...` | Fornecedores a Pagar | `2.1.02.001`     | `2102001`       | Passivo Circulante  | `C`    |

---

## Fluxo 5 — Layout de Importação

O layout define como as colunas do arquivo serão mapeadas para os campos do registro.

### FormRequest: `StoreLayoutRequest`

```php
'contract_id'   => 'required|uuid|exists:contracts,id',
'name'          => 'required|string|max:255',
'format'        => 'required|in:Excel,OFX,TXT,CSV,CNAB240,CNAB400',
'sector'        => 'nullable|in:Contábil,Fiscal',
'movement_type' => 'required|in:Ambos,Pagar,Receber',
'start_row'     => 'required|integer|min:1',

// Mapeamento de colunas do arquivo
'date_column'                    => 'required|string|max:10',
'history_column'                 => 'required|string|max:10',
'debit_value_column'             => 'nullable|string|max:10',
'credit_value_column'            => 'nullable|string|max:10',
'num_doc_column'                 => 'nullable|string|max:10',
'client_supplier_column'         => 'nullable|string|max:10',
'bank_column'                    => 'nullable|string|max:10',
'filial_column'                  => 'nullable|integer',
'debit_credit_column'            => 'nullable|string|max:10',
'cpf_cnpj_column'                => 'nullable|integer',
'additional_information_column'  => 'nullable|integer',
'additional_information_2_column'=> 'nullable|integer',
'additional_information_3_column'=> 'nullable|integer',
'complement_column'              => 'nullable|integer',
'debit_account_column'           => 'nullable|integer',
'credit_account_column'          => 'nullable|integer',
'third_party_participant_column' => 'nullable|integer',
'parcel_separator'               => 'nullable|string|max:10',

// Flags de comportamento
'consider_previous_date'             => 'nullable|boolean',
'consider_previous_client_supplier'  => 'nullable|boolean',
'consider_previous_history'          => 'nullable|boolean',
'consider_previous_filial'           => 'nullable|boolean',
'consider_previous_bank'             => 'nullable|boolean',
'invert_sign'                        => 'nullable|boolean',
'import_blocked_entries'             => 'nullable|boolean',
'bank_statement'                     => 'nullable|boolean',
'participant_marking_enabled'        => 'nullable|boolean',

// Flags de regras contábeis
'consider_dc_for_accounting_rules'              => 'nullable|boolean',
'consider_history_for_accounting_rules'         => 'nullable|boolean',
'consider_participant_doc_for_accounting_rules' => 'nullable|boolean',
'consider_participant_for_accounting_rules'     => 'nullable|boolean',
'consider_bank_for_accounting_rules'            => 'nullable|boolean',
'consider_filial_for_accounting_rules'          => 'nullable|boolean',
'consider_additional_info_for_accounting_rules' => 'nullable|boolean',
```

### Exemplo de Payload JSON

```json
{
  "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "name": "Extrato Bancário Bradesco",
  "format": "Excel",
  "sector": "Contábil",
  "movement_type": "Ambos",
  "start_row": 2,
  "date_column": "A",
  "history_column": "B",
  "debit_value_column": "C",
  "credit_value_column": "D",
  "num_doc_column": "E",
  "client_supplier_column": "F",
  "bank_column": null,
  "filial_column": null,
  "debit_credit_column": null,
  "consider_previous_date": false,
  "consider_previous_history": false,
  "invert_sign": false,
  "bank_statement": true,
  "consider_dc_for_accounting_rules": true,
  "consider_history_for_accounting_rules": true,
  "consider_participant_doc_for_accounting_rules": false,
  "consider_bank_for_accounting_rules": false
}
```

### Como fica no banco (`layouts`)

| campo                                 | valor                                        |
| ------------------------------------- | -------------------------------------------- |
| id                                    | `c9d0e1f2-a3b4-5678-cdef-456789012345`       |
| **code**                              | `42` ← **gerado por sequence do PostgreSQL** |
| contract_id                           | `a1b2c3d4-...`                               |
| name                                  | `Extrato Bancário Bradesco`                  |
| format                                | `Excel`                                      |
| sector                                | `Contábil`                                   |
| movement_type                         | `Ambos`                                      |
| start_row                             | `2`                                          |
| date_column                           | `A`                                          |
| history_column                        | `B`                                          |
| debit_value_column                    | `C`                                          |
| credit_value_column                   | `D`                                          |
| num_doc_column                        | `E`                                          |
| client_supplier_column                | `F`                                          |
| bank_column                           | `null`                                       |
| bank_statement                        | `true`                                       |
| consider_dc_for_accounting_rules      | `true`                                       |
| consider_history_for_accounting_rules | `true`                                       |
| created_at                            | `2025-03-23 10:05:00`                        |
| updated_at                            | `2025-03-23 10:05:00`                        |

> ⚠️ **`code` nunca deve ser enviado na inserção** — é gerado automaticamente via sequence do banco.

---

## Fluxo 6 — Empresa

### FormRequest: `StoreCompanyRequest`

```php
'contract_id'      => 'required|uuid|exists:contracts,id',
'code'             => 'required|string|unique:companies,code',
'cpf_cnpj'         => 'required|string|size:14|unique:companies,cpf_cnpj',
'corporate_name'   => 'nullable|string|max:255',
'external_code'    => 'nullable|string|max:20',
'tax_regime'       => 'required|in:Lucro Real,Lucro Presumido,Simples Nacional,Outros',
'street'           => 'nullable|string|max:255',
'number'           => 'nullable|string|max:50',
'neighborhood'     => 'nullable|string|max:100',
'city'             => 'nullable|string|max:100',
'complement'       => 'nullable|string',
'state'            => 'nullable|string|size:2',
'zipcode'          => 'nullable|string|max:10',
'state_registration'  => 'nullable|string|max:20',
'city_registration'   => 'nullable|string|max:20',
'phone'            => 'nullable|string|max:15',
'phone_cell'       => 'nullable|string|max:15',
'email'            => 'nullable|email|max:100',
'is_active'        => 'nullable|boolean',
'observation'      => 'nullable|string',
'plan_id'          => 'nullable|uuid|exists:plans,id',
'rules_sharing_id' => 'nullable|uuid|exists:rules_sharings,id',
'use_participant'  => 'nullable|boolean',
'use_cost_center'  => 'nullable|boolean',
'use_auto_register_of_people' => 'nullable|boolean',
```

### Exemplo de Payload JSON

```json
{
  "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "code": "EMP001",
  "cpf_cnpj": "98765432000188",
  "corporate_name": "Comércio e Serviços Oliveira ME",
  "external_code": "CLI-2234",
  "tax_regime": "Simples Nacional",
  "street": "Av. Paulista",
  "number": "1578",
  "neighborhood": "Bela Vista",
  "city": "São Paulo",
  "state": "SP",
  "zipcode": "01310200",
  "state_registration": "111222333444",
  "city_registration": "CCM-12345",
  "phone": "1132141234",
  "email": "financeiro@oliveira.com.br",
  "is_active": true,
  "plan_id": "e5f6a7b8-c9d0-1234-efab-345678901234",
  "rules_sharing_id": null,
  "use_participant": true,
  "use_cost_center": false,
  "use_auto_register_of_people": true
}
```

### Como fica no banco (`companies`)

| campo                       | valor                                             |
| --------------------------- | ------------------------------------------------- |
| id                          | `d0e1f2a3-b4c5-6789-defa-567890123456`            |
| contract_id                 | `a1b2c3d4-...`                                    |
| code                        | `EMP001`                                          |
| cpf_cnpj                    | `98765432000188`                                  |
| corporate_name              | `Comércio e Serviços Oliveira ME`                 |
| external_code               | `CLI-2234`                                        |
| tax_regime                  | `Simples Nacional`                                |
| city                        | `SÃO PAULO` ← mutator converte para **UPPERCASE** |
| state                       | `SP`                                              |
| is_active                   | `true`                                            |
| plan_id                     | `e5f6a7b8-...`                                    |
| rules_sharing_id            | `null`                                            |
| use_participant             | `true`                                            |
| use_auto_register_of_people | `true`                                            |
| created_at                  | `2025-03-23 10:06:00`                             |
| updated_at                  | `2025-03-23 10:06:00`                             |

---

## Fluxo 7 — Vínculo Empresa × Layout (`company_layout`)

> **Tabela pivot mais crítica do sistema.** Sem este registro não é possível realizar importações.

### FormRequest: `StoreCompanyLayoutRequest`

```php
'company_id'    => 'required|uuid|exists:companies,id',
'layout_imp'    => 'required|uuid|exists:layouts,id',
'layout_exp'    => 'required|uuid|exists:layout_admins,id',  // FK para layout de exportação
'type_accounting'  => 'nullable|in:DCH,DC,LA',
'credit_account'   => 'nullable|string|max:20',
'debit_account'    => 'nullable|string|max:20',
'account_fixed'    => 'nullable|boolean',
'bank'             => 'nullable|string|max:50',

// Contas para valores desmembrados — Débito
'value_debit'               => 'nullable|string|max:50',
'value_code_history_debit'  => 'nullable|string|max:50',
'value_history_debit'       => 'nullable|string|max:50',
'fees_debit'                => 'nullable|string|max:50',
'fees_code_history_debit'   => 'nullable|string|max:50',
'fees_history_debit'        => 'nullable|string|max:50',
'fine_debit'                => 'nullable|string|max:50',
'discount_debit'            => 'nullable|string|max:50',
'others_debit'              => 'nullable|string|max:50',
'refunds_debit'             => 'nullable|string|max:50',
'rates_debit'               => 'nullable|string|max:50',

// Contas para valores desmembrados — Crédito (mesmos campos com _credit)
'value_credit' / 'fees_credit' / 'fine_credit' / ...
```

### Exemplo de Payload JSON

```json
{
  "company_id": "d0e1f2a3-b4c5-6789-defa-567890123456",
  "layout_imp": "c9d0e1f2-a3b4-5678-cdef-456789012345",
  "layout_exp": "admin-layout-uuid-aqui",
  "type_accounting": "DC",
  "credit_account": "2102001",
  "debit_account": "1101001",
  "account_fixed": false,
  "bank": "Bradesco",
  "value_debit": "1101001",
  "value_code_history_debit": "001",
  "value_history_debit": "PAGAMENTO A FORNECEDOR",
  "fees_debit": "3201001",
  "fees_history_debit": "JUROS PAGOS",
  "discount_debit": null,
  "fine_debit": "3202001",
  "fine_history_debit": "MULTAS PAGAS",
  "value_credit": "2102001",
  "value_code_history_credit": "002",
  "value_history_credit": "RECEBIMENTO DE CLIENTE"
}
```

### Como fica no banco (`company_layout`)

| campo               | valor                                  |
| ------------------- | -------------------------------------- |
| id                  | `e1f2a3b4-c5d6-7890-efab-678901234567` |
| company_id          | `d0e1f2a3-...`                         |
| layout_imp          | `c9d0e1f2-...`                         |
| layout_exp          | `admin-layout-uuid`                    |
| type_accounting     | `DC`                                   |
| credit_account      | `2102001`                              |
| debit_account       | `1101001`                              |
| account_fixed       | `false`                                |
| bank                | `Bradesco`                             |
| value_debit         | `1101001`                              |
| value_history_debit | `PAGAMENTO A FORNECEDOR`               |
| fees_debit          | `3201001`                              |
| fees_history_debit  | `JUROS PAGOS`                          |
| fine_debit          | `3202001`                              |
| created_at          | `2025-03-23 10:07:00`                  |
| updated_at          | `2025-03-23 10:07:00`                  |

---

## Fluxo 8 — Participantes

### 8a. INSERT `peoples`

```php
'contract_id'    => 'required|uuid|exists:contracts,id',
'cpf_cnpj'       => 'nullable|string|max:14',   // ao menos um dos dois é obrigatório
'corporate_name' => 'required|string|max:100',
// CONSTRAINT DB: cpf_cnpj IS NOT NULL OR corporate_name IS NOT NULL
```

### Exemplo de Payload JSON

```json
[
  {
    "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "cpf_cnpj": "11122233344",
    "corporate_name": "João da Silva"
  },
  {
    "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "cpf_cnpj": "55666777000199",
    "corporate_name": "Distribuidora ABC Ltda"
  }
]
```

### Como fica no banco (`peoples`)

| id         | contract_id | cpf_cnpj         | corporate_name           |
| ---------- | ----------- | ---------------- | ------------------------ |
| `f2a3-...` | `a1b2-...`  | `11122233344`    | `João da Silva`          |
| `a3b4-...` | `a1b2-...`  | `55666777000199` | `Distribuidora ABC Ltda` |

### 8b. INSERT `people_vinculated`

```php
'people_id'        => 'required|uuid|exists:peoples,id',
'company_id'       => 'nullable|uuid|exists:companies,id',       // exclusivo
'rules_sharing_id' => 'nullable|uuid|exists:rules_sharings,id',  // exclusivo
'debit_account'    => 'nullable|string|max:10',
'credit_account'   => 'nullable|string|max:10',
'participant'      => 'nullable|string|max:100',
'vinculated_name'  => 'nullable|string|max:150',
// CONSTRAINT DB: exatamente um de company_id OU rules_sharing_id deve ser preenchido
```

### Exemplo de Payload JSON

```json
{
  "people_id": "f2a3b4c5-d6e7-8901-fabc-789012345678",
  "company_id": "d0e1f2a3-b4c5-6789-defa-567890123456",
  "rules_sharing_id": null,
  "debit_account": "2102001",
  "credit_account": "1101001",
  "participant": "JOAO SILVA",
  "vinculated_name": "João da Silva - Fornecedor"
}
```

### Como fica no banco (`people_vinculated`)

| campo            | valor                                  |
| ---------------- | -------------------------------------- |
| id               | `a3b4c5d6-e7f8-9012-abcd-890123456789` |
| people_id        | `f2a3b4c5-...`                         |
| company_id       | `d0e1f2a3-...`                         |
| rules_sharing_id | `null`                                 |
| debit_account    | `2102001`                              |
| credit_account   | `1101001`                              |
| participant      | `JOAO SILVA`                           |
| vinculated_name  | `João da Silva - Fornecedor`           |

---

## Fluxo 9 — Importação de Dados Transacionais

Este é o fluxo central. Três tabelas são criadas em sequência: `imports` → `import_sessions` → `import_records`.

### PASSO 9.1 — INSERT `imports`

```php
'name'              => 'required|string|max:255',
'user_id'           => 'required|uuid|exists:users,id',
'company_id'        => 'required|uuid|exists:companies,id',
'company_layout_id' => 'required|uuid|exists:company_layout,id',
'contract_id'       => 'required|uuid|exists:contracts,id',
'total_files'       => 'required|integer|min:1',
'initial_period'    => 'nullable|date',
'final_period'      => 'nullable|date|after_or_equal:initial_period',
'previous_balance'  => 'nullable|numeric',
// system-set:
// status = 'processing'
// conciliation_status = 'pending'
// is_big_import = false
// error_message = ''
```

### Exemplo de Payload JSON

```json
{
  "name": "Extrato Bradesco Janeiro 2025",
  "user_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "company_id": "d0e1f2a3-b4c5-6789-defa-567890123456",
  "company_layout_id": "e1f2a3b4-c5d6-7890-efab-678901234567",
  "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "total_files": 1,
  "initial_period": "2025-01-01",
  "final_period": "2025-01-31",
  "previous_balance": null
}
```

### Como fica no banco (`imports`)

| campo                   | valor                                  |
| ----------------------- | -------------------------------------- |
| id                      | `b4c5d6e7-f8a9-0123-bcde-901234567890` |
| name                    | `Extrato Bradesco Janeiro 2025`        |
| user_id                 | `b2c3d4e5-...`                         |
| company_id              | `d0e1f2a3-...`                         |
| company_layout_id       | `e1f2a3b4-...`                         |
| contract_id             | `a1b2c3d4-...`                         |
| **status**              | **`processing`**                       |
| **conciliation_status** | **`pending`**                          |
| total_files             | `1`                                    |
| initial_period          | `2025-01-01`                           |
| final_period            | `2025-01-31`                           |
| **is_big_import**       | **`false`**                            |
| **error_message**       | **`''`**                               |
| created_at              | `2025-03-23 10:10:00`                  |
| updated_at              | `2025-03-23 10:10:00`                  |

---

### PASSO 9.2 — INSERT `import_sessions`

```php
'import_id'          => 'required|uuid|exists:imports,id',
'layout_id'          => 'required|uuid|exists:layouts,id',  // = company_layout.layout_imp
'original_file_name' => 'required|string|max:255',
'file_name'          => 'required|string',                  // nome interno ou 'api-batch'
'date_to_create'     => 'nullable|string',                  // formato: MM-YYYY ou YYYY
'size'               => 'nullable|integer',
// system-set: status = 'pending'
```

### Exemplo de Payload JSON

```json
{
  "import_id": "b4c5d6e7-f8a9-0123-bcde-901234567890",
  "layout_id": "c9d0e1f2-a3b4-5678-cdef-456789012345",
  "original_file_name": "extrato_bradesco_jan2025.xlsx",
  "file_name": "api-batch",
  "date_to_create": "01-2025",
  "size": null
}
```

### Como fica no banco (`import_sessions`)

| campo              | valor                                  |
| ------------------ | -------------------------------------- |
| id                 | `c5d6e7f8-a9b0-1234-cdef-012345678901` |
| import_id          | `b4c5d6e7-...`                         |
| layout_id          | `c9d0e1f2-...`                         |
| **status**         | **`pending`**                          |
| original_file_name | `extrato_bradesco_jan2025.xlsx`        |
| file_name          | `api-batch`                            |
| date_to_create     | `01-2025`                              |
| size               | `null`                                 |
| converter_id       | `null`                                 |
| current_page       | `null`                                 |
| created_at         | `2025-03-23 10:10:01`                  |
| updated_at         | `2025-03-23 10:10:01`                  |

---

### PASSO 9.3 — INSERT `import_records` (lotes de 1000)

```php
// Obrigatórios
'import_id'         => 'required|uuid',
'import_session_id' => 'required|uuid',
'date'              => 'required|date_format:Y-m-d',
'history'           => 'required|string',

// Transacionais
'value'             => 'nullable|numeric',          // sempre positivo
'debit_credit'      => 'nullable|in:D,C',           // D=Débito C=Crédito
'num_doc'           => 'nullable|string|max:255',
'client_supplier'   => 'nullable|string|max:255',
'bank'              => 'nullable|string|max:255',
'filial'            => 'nullable|string|max:10',
'cpf_cnpj'          => 'nullable|string|max:14',
'debit_account'     => 'nullable|string|max:15',    // truncar + remover espaços
'credit_account'    => 'nullable|string|max:15',    // truncar + remover espaços
'complement'        => 'nullable|string',
'additional_information'   => 'nullable|string',    // ⚠️ sem sufixo _1
'additional_information_2' => 'nullable|string|max:255',
'additional_information_3' => 'nullable|string|max:255',
'parcel'            => 'nullable|string|max:3',
'third_party_participant' => 'nullable|string|max:150',
'due_date'          => 'nullable|date',

// Impostos / valores desmembrados
'refund_values'      => 'nullable|numeric',
'pis_value'          => 'nullable|numeric',
'cofins_value'       => 'nullable|numeric',
'csll_value'         => 'nullable|numeric',
'irrf_value'         => 'nullable|numeric',

// System-set (nunca enviar do payload externo)
// id              = Str::orderedUuid()->toString()
// order_number    = 1, 2, 3... sequencial por import_session
// checked         = false
// is_conciliated  = false
// is_manual       = false
// was_exported    = false
// selected        = false
// not_considered  = false
// is_split        = false
// is_from_confrontation = false
// was_entered_manually  = false
// created_at      = now()
// updated_at      = now()
```

### Exemplo de Payload JSON (lote de 5 registros)

```json
{
  "import_id": "b4c5d6e7-f8a9-0123-bcde-901234567890",
  "import_session_id": "c5d6e7f8-a9b0-1234-cdef-012345678901",
  "records": [
    {
      "date": "2025-01-03",
      "history": "PAGAMENTO FORNECEDOR DISTRIBUIDORA ABC",
      "debit_value": "3500.00",
      "credit_value": null,
      "num_doc": "NF-001234",
      "client_supplier": "DISTRIBUIDORA ABC LTDA",
      "cpf_cnpj": "55666777000199",
      "bank": "Bradesco",
      "filial": "SP01",
      "debit_account": "2102001",
      "credit_account": "1101001"
    },
    {
      "date": "2025-01-07",
      "history": "RECEBIMENTO CLIENTE JOAO SILVA",
      "debit_value": null,
      "credit_value": "1200.00",
      "num_doc": "REC-00456",
      "client_supplier": "JOAO DA SILVA",
      "cpf_cnpj": "11122233344",
      "bank": "Bradesco"
    },
    {
      "date": "2025-01-10",
      "history": "PAGAMENTO FORNECEDOR COM JUROS",
      "debit_value": "2800.00",
      "credit_value": null,
      "num_doc": "NF-001567",
      "client_supplier": "DISTRIBUIDORA ABC LTDA",
      "cpf_cnpj": "55666777000199",
      "refund_values": null,
      "pis_value": "42.00",
      "cofins_value": "126.00",
      "csll_value": "56.00",
      "irrf_value": "28.00"
    },
    {
      "date": "2025-01-15",
      "history": "TARIFAS BANCARIAS",
      "debit_value": "45.90",
      "credit_value": null,
      "num_doc": null,
      "client_supplier": "BRADESCO S.A.",
      "bank": "Bradesco",
      "complement": "TARIFAS"
    },
    {
      "date": "2025-01-28",
      "history": "RECEBIMENTO PARCELADO",
      "debit_value": null,
      "credit_value": "600.00",
      "num_doc": "REC-00789",
      "client_supplier": "JOAO DA SILVA",
      "cpf_cnpj": "11122233344",
      "parcel": "2/3"
    }
  ]
}
```

### Como fica no banco (`import_records`)

| id         | order | date       | history                 | value   | debit_credit | num_doc   | client_supplier        | cpf_cnpj       | debit_account | credit_account | parcel |
| ---------- | ----- | ---------- | ----------------------- | ------- | ------------ | --------- | ---------------------- | -------------- | ------------- | -------------- | ------ |
| `01e7-...` | 1     | 2025-01-03 | PAGAMENTO FORNECEDOR... | 3500.00 | D            | NF-001234 | DISTRIBUIDORA ABC LTDA | 55666777000199 | 2102001       | 1101001        | null   |
| `01e8-...` | 2     | 2025-01-07 | RECEBIMENTO CLIENTE...  | 1200.00 | C            | REC-00456 | JOAO DA SILVA          | 11122233344    | null          | null           | null   |
| `01e9-...` | 3     | 2025-01-10 | PAGAMENTO FORNECEDOR... | 2800.00 | D            | NF-001567 | DISTRIBUIDORA ABC LTDA | 55666777000199 | null          | null           | null   |
| `01ea-...` | 4     | 2025-01-15 | TARIFAS BANCARIAS       | 45.90   | D            | null      | BRADESCO S.A.          | null           | null          | null           | null   |
| `01eb-...` | 5     | 2025-01-28 | RECEBIMENTO PARCELADO   | 600.00  | C            | REC-00789 | JOAO DA SILVA          | 11122233344    | null          | null           | 2/3    |

> UUIDs dos `id` são gerados com `Str::orderedUuid()` — ordenados cronologicamente.

> Para o registro 3, os campos de impostos ficam:

| id         | pis_value | cofins_value | csll_value | irrf_value |
| ---------- | --------- | ------------ | ---------- | ---------- |
| `01e9-...` | 42.00     | 126.00       | 56.00      | 28.00      |

### PASSO 9.4 — Finalizar status após inserção

```sql
-- 1. Marcar sessão como completed
UPDATE import_sessions
SET status = 'completed', updated_at = NOW()
WHERE id = 'c5d6e7f8-a9b0-1234-cdef-012345678901';

-- 2. Contar registros importados
SELECT COUNT(*) FROM import_records WHERE import_id = 'b4c5d6e7-...';
-- Resultado: 5

-- 3. Marcar import como completed
UPDATE imports
SET
  status = 'completed',
  is_big_import = (5 > 10000),  -- false
  updated_at = NOW()
WHERE id = 'b4c5d6e7-f8a9-0123-bcde-901234567890';
```

### Estado final da tabela `imports`

| campo         | antes        | depois      |
| ------------- | ------------ | ----------- |
| status        | `processing` | `completed` |
| is_big_import | `false`      | `false`     |
| error_message | `''`         | `''`        |

---

## Fluxo 10 — Regras Contábeis

Regras definem como lançamentos serão codificados contabilmente. Podem ser inseridas antes ou depois dos `import_records` — são aplicadas pelo `RulesProcessor` após a importação.

### FormRequest: `StoreRuleRequest`

```php
'company_id'   => 'required|uuid|exists:companies,id',
'layout_id'    => 'required|uuid|exists:layouts,id',
'contract_id'  => 'required|uuid|exists:contracts,id',

// Padrões de matching (pelo menos um deve ser preenchido)
'debit_credit'    => 'nullable|in:D,C',
'cpf_cnpj'        => 'nullable|string',
'client_supplier' => 'nullable|string',
'history'         => 'nullable|string',
'bank'            => 'nullable|string',
'filial'          => 'nullable|string',
'additional_information'   => 'nullable|string',
'additional_information_2' => 'nullable|string',
'additional_information_3' => 'nullable|string',
'token'           => 'nullable|string',

// Destinos contábeis
'id_history'           => 'nullable|string|max:10',  // código do histórico
'id_debit'             => 'nullable|string|max:10',  // conta débito
'id_credit'            => 'nullable|string|max:10',  // conta crédito
'id_history_exp'       => 'nullable|string',
'id_participant_credit'=> 'nullable|string|max:10',
'id_participant_debit' => 'nullable|string|max:10',
'id_cc_credit'         => 'nullable|string|max:10',
'id_cc_debit'          => 'nullable|string|max:10',

// Controle
'exclusive'       => 'nullable|boolean',
'reprocess'       => 'nullable|boolean',
'invalid'         => 'nullable|boolean',
'sort_order'      => 'nullable|integer',
'automatic_launch'=> 'nullable|boolean',
'rule_extra'      => 'nullable|string',
```

### Exemplo de Payload JSON (3 regras)

```json
[
  {
    "company_id": "d0e1f2a3-b4c5-6789-defa-567890123456",
    "layout_id": "c9d0e1f2-a3b4-5678-cdef-456789012345",
    "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "debit_credit": "D",
    "client_supplier": "DISTRIBUIDORA ABC",
    "history": null,
    "cpf_cnpj": "55666777000199",
    "id_history": "001",
    "id_debit": "2102001",
    "id_credit": "1101001",
    "exclusive": false,
    "sort_order": 1,
    "automatic_launch": false
  },
  {
    "company_id": "d0e1f2a3-b4c5-6789-defa-567890123456",
    "layout_id": "c9d0e1f2-a3b4-5678-cdef-456789012345",
    "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "debit_credit": "C",
    "client_supplier": "JOAO DA SILVA",
    "id_history": "002",
    "id_debit": "1101001",
    "id_credit": "3101001",
    "exclusive": false,
    "sort_order": 2
  },
  {
    "company_id": "d0e1f2a3-b4c5-6789-defa-567890123456",
    "layout_id": "c9d0e1f2-a3b4-5678-cdef-456789012345",
    "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "debit_credit": "D",
    "history": "TARIFAS",
    "id_history": "003",
    "id_debit": "3202001",
    "id_credit": "1101001",
    "exclusive": true,
    "sort_order": 3
  }
]
```

### Como fica no banco (`rules`)

| id         | company_id | layout_id  | debit_credit | client_supplier   | cpf_cnpj       | history | id_history | id_debit | id_credit | exclusive | sort_order |
| ---------- | ---------- | ---------- | ------------ | ----------------- | -------------- | ------- | ---------- | -------- | --------- | --------- | ---------- |
| `d6e7-...` | `d0e1-...` | `c9d0-...` | D            | DISTRIBUIDORA ABC | 55666777000199 | null    | 001        | 2102001  | 1101001   | false     | 1          |
| `e7f8-...` | `d0e1-...` | `c9d0-...` | C            | JOAO DA SILVA     | null           | null    | 002        | 1101001  | 3101001   | false     | 2          |
| `f8a9-...` | `d0e1-...` | `c9d0-...` | D            | null              | null           | TARIFAS | 003        | 3202001  | 1101001   | true      | 3          |

---

## Fluxo 11 — Conciliação Banco × Financeiro

### 11a. INSERT `confrontations`

```php
'contract_id'    => 'required|uuid|exists:contracts,id',
'company_id'     => 'required|uuid|exists:companies,id',
'description'    => 'required|string|max:255',
'user_create_id' => 'required|uuid|exists:users,id',
'user_create'    => 'required|string|max:255',     // nome denormalizado
'company_name'   => 'nullable|string|max:255',     // denormalizado
'company_cnpj'   => 'nullable|string|max:14',      // denormalizado
'consider_date'          => 'nullable|boolean',
'consider_debit_credit'  => 'nullable|boolean',
'consider_document'      => 'nullable|boolean',
'consider_history'       => 'nullable|boolean',
'ignore_equals'          => 'nullable|boolean',
'selected_bank_financial'=> 'nullable|string',
'selected_bank_bank'     => 'nullable|string',
'layouts'                => 'nullable|string',
// system-set: status = 'pending'
```

### Exemplo de Payload JSON

```json
{
  "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "company_id": "d0e1f2a3-b4c5-6789-defa-567890123456",
  "description": "Conciliação Bradesco Janeiro 2025",
  "user_create_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "user_create": "Ana Paula Ferreira",
  "company_name": "Comércio e Serviços Oliveira ME",
  "company_cnpj": "98765432000188",
  "consider_date": true,
  "consider_debit_credit": true,
  "consider_document": false,
  "consider_history": false,
  "ignore_equals": true,
  "selected_bank_financial": "Extrato Bradesco",
  "selected_bank_bank": "Extrato Bradesco"
}
```

### Como fica no banco (`confrontations`)

| campo                 | valor                                  |
| --------------------- | -------------------------------------- |
| id                    | `a9b0c1d2-e3f4-5678-abcd-901234567890` |
| contract_id           | `a1b2c3d4-...`                         |
| company_id            | `d0e1f2a3-...`                         |
| description           | `Conciliação Bradesco Janeiro 2025`    |
| user_create_id        | `b2c3d4e5-...`                         |
| user_create           | `Ana Paula Ferreira`                   |
| company_name          | `Comércio e Serviços Oliveira ME`      |
| company_cnpj          | `98765432000188`                       |
| **status**            | **`pending`**                          |
| consider_date         | `true`                                 |
| consider_debit_credit | `true`                                 |
| consider_document     | `false`                                |
| ignore_equals         | `true`                                 |
| is_bulk_linked        | `false`                                |

### 11b. INSERT `confrontation_records`

```php
'confrontation_id' => 'required|uuid|exists:confrontations,id',
'import_record_id' => 'nullable|uuid|exists:import_records,id',
'import_id'        => 'nullable|uuid|exists:imports,id',
'date'             => 'required|date',
'layout_code'      => 'required|string|max:5',
'debit_credit'     => 'required|in:D,C',
'value'            => 'required|numeric',
'records_origin'   => 'nullable|in:F,B',   // F=Financeiro B=Banco
'history'          => 'nullable|string',
'client_supplier'  => 'nullable|string|max:255',
'num_doc'          => 'nullable|string|max:255',
'bank'             => 'nullable|string|max:100',
'cpf_cnpj'         => 'nullable|string|max:14',
'conciliated_value'=> 'nullable|numeric',
// system-set: selected=false, conciliated=false, order_number=sequencial
```

### Como fica no banco (`confrontation_records`)

| id         | confrontation_id | import_record_id | date       | layout_code | debit_credit | value   | records_origin | conciliated | history                 |
| ---------- | ---------------- | ---------------- | ---------- | ----------- | ------------ | ------- | -------------- | ----------- | ----------------------- |
| `b0c1-...` | `a9b0-...`       | `01e7-...`       | 2025-01-03 | 42          | D            | 3500.00 | B              | false       | PAGAMENTO FORNECEDOR... |
| `c1d2-...` | `a9b0-...`       | `01e8-...`       | 2025-01-07 | 42          | C            | 1200.00 | F              | false       | RECEBIMENTO CLIENTE...  |
| `d2e3-...` | `a9b0-...`       | `01ea-...`       | 2025-01-15 | 42          | D            | 45.90   | B              | false       | TARIFAS BANCARIAS       |

---

## Fluxo 12 — Exportação

### FormRequest: `StoreExportRequest`

```php
'contract_id' => 'required|uuid|exists:contracts,id',
'import_id'   => 'required|uuid|exists:imports,id',
'user_id'     => 'required|uuid|exists:users,id',
'company_id'  => 'required|uuid|exists:companies,id',
'name'        => 'required|string|max:255',
'config'      => 'required|array',  // configurações específicas de exportação
// system-set:
// status          = 'pending'
// is_active       = true
// download_count  = 0
// file_expiry_date = null (preenchido após completed + 24h)
```

### Exemplo de Payload JSON

```json
{
  "contract_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "import_id": "b4c5d6e7-f8a9-0123-bcde-901234567890",
  "user_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "company_id": "d0e1f2a3-b4c5-6789-defa-567890123456",
  "name": "Exportação Contábil Jan/2025 - Oliveira",
  "config": {
    "format": "txt",
    "include_history": true,
    "include_participant": false
  }
}
```

### Como fica no banco (`exports`)

| campo             | criação                                | após processamento                    |
| ----------------- | -------------------------------------- | ------------------------------------- |
| id                | `e3f4a5b6-c7d8-9012-efab-012345678901` | —                                     |
| status            | **`pending`**                          | **`completed`**                       |
| total_records     | `null`                                 | `5`                                   |
| processed_records | `null`                                 | `5`                                   |
| file_name         | `null`                                 | `["export_jan2025.txt"]` (JSON array) |
| started_at        | `null`                                 | `2025-03-23 10:30:00`                 |
| completed_at      | `null`                                 | `2025-03-23 10:30:45`                 |
| file_expiry_date  | `null`                                 | `2025-03-24 10:30:45` (+24h)          |
| download_count    | `0`                                    | `0`                                   |
| is_active         | `true`                                 | `true`                                |

**Ciclo de vida do status:**

```
pending ──► processing ──► completed ──► expired (após 24h)
                      └──► failed
```

---

## Armadilhas e Pontos Críticos

### ⚠️ Colunas com nomes inesperados

| Tabela           | Campo no payload               | Campo no banco           | Motivo                                                |
| ---------------- | ------------------------------ | ------------------------ | ----------------------------------------------------- |
| `import_records` | `additional_information_1`     | `additional_information` | Coluna original não tem sufixo `_1`                   |
| `import_records` | `debit_value` + `credit_value` | `value` + `debit_credit` | `debit_value` foi removida; derivar `value` e `D`/`C` |

### ⚠️ Mutators do Model que não atuam via `DB::table()->insert()`

| Model          | Campo            | Comportamento                         | Ação manual             |
| -------------- | ---------------- | ------------------------------------- | ----------------------- |
| `ImportRecord` | `debit_account`  | Trunca para 15 chars e remove espaços | Aplicar antes do insert |
| `ImportRecord` | `credit_account` | Trunca para 15 chars e remove espaços | Aplicar antes do insert |
| `Company`      | `city`           | Converte para UPPERCASE               | Aplicar antes do insert |

### ⚠️ UUIDs

| Tabela           | Tipo de UUID          | Como gerar                                       |
| ---------------- | --------------------- | ------------------------------------------------ |
| `import_records` | **Ordered UUID** (v1) | `Str::orderedUuid()->toString()`                 |
| Todas as demais  | UUID v4 aleatório     | `Str::uuid()->toString()` ou `gen_random_uuid()` |

> O `orderedUuid` é importante para performance de índices no PostgreSQL.

### ⚠️ Campos gerados automaticamente (não enviar)

| Tabela    | Campo                      | Gerado por                 |
| --------- | -------------------------- | -------------------------- |
| `layouts` | `code`                     | Sequence do PostgreSQL     |
| Todos     | `id`                       | PHP ou `gen_random_uuid()` |
| Todos     | `created_at`, `updated_at` | Aplicação                  |

### ⚠️ Constraints do banco

```sql
-- peoples: ao menos um dos dois campos deve ser preenchido
CHECK (cpf_cnpj IS NOT NULL OR corporate_name IS NOT NULL)

-- people_vinculated: exatamente um dos dois deve ser preenchido
CHECK (
  (company_id IS NULL AND rules_sharing_id IS NOT NULL) OR
  (company_id IS NOT NULL AND rules_sharing_id IS NULL)
)
```

### ⚠️ Forbidden Characters — limpeza pós-insert

Após inserir todos os `import_records`, chamar o serviço de limpeza:

```php
// PHP
app(CleanImportRecordsForbiddenCharactersService::class)->execute($importId);

// O serviço limpa os campos: history, client_supplier, bank,
// additional_information, complement, filial — baseado nas regras
// de caracteres proibidos configuradas por layout
```

### ⚠️ Enums e valores aceitos

| Tabela.campo                           | Valores válidos                                               |
| -------------------------------------- | ------------------------------------------------------------- |
| `imports.status`                       | `pending`, `processing`, `completed`, `failed`                |
| `exports.status`                       | `pending`, `processing`, `completed`, `failed`, `expired`     |
| `confrontations.status`                | `pending`, `completed`                                        |
| `import_records.debit_credit`          | `D`, `C`                                                      |
| `import_records.conciliation_status`   | `Y`, `F`, `N` (ou null)                                       |
| `confrontation_records.records_origin` | `F` (financeiro), `B` (banco)                                 |
| `confrontation_records.debit_credit`   | `D`, `C`                                                      |
| `companies.tax_regime`                 | `Lucro Real`, `Lucro Presumido`, `Simples Nacional`, `Outros` |
| `plan_items.origin`                    | `C`, `D`, `I`                                                 |
| `contracts.contractor_type`            | `individual`, `company`                                       |
| `layouts.movement_type`                | `Ambos`, `Pagar`, `Receber`                                   |
| `layouts.sector`                       | `Contábil`, `Fiscal`                                          |

---

## Resumo dos FormRequests

| #   | FormRequest                       | Tabela(s) criadas                                |
| --- | --------------------------------- | ------------------------------------------------ |
| 1   | `StoreContractRequest`            | `contracts`                                      |
| 2a  | `StoreUserRequest`                | `users`                                          |
| 2b  | `AttachUserToContractRequest`     | `contract_user`                                  |
| 3   | `StoreRulesSharingRequest`        | `rules_sharings`                                 |
| 4a  | `StorePlanRequest`                | `plans`                                          |
| 4b  | `StorePlanItemRequest`            | `plan_items`                                     |
| 5   | `StoreLayoutRequest`              | `layouts`                                        |
| 6   | `StoreCompanyRequest`             | `companies`                                      |
| 7   | `StoreCompanyLayoutRequest`       | `company_layout`                                 |
| 8a  | `StorePeopleRequest`              | `peoples`                                        |
| 8b  | `StorePeopleVinculatedRequest`    | `people_vinculated`                              |
| 9   | `BatchImportRequest`              | `imports` + `import_sessions` + `import_records` |
| 10  | `StoreRuleRequest`                | `rules`                                          |
| 11a | `StoreConfrontationRequest`       | `confrontations`                                 |
| 11b | `StoreConfrontationRecordRequest` | `confrontation_records`                          |
| 12  | `StoreExportRequest`              | `exports`                                        |

---

## Checklist de Migração

```
[ ] 1. Consultar IDs de status existentes: SELECT id, name FROM status
[ ] 2. Consultar roles existentes: SELECT id, name FROM roles
[ ] 3. Inserir contracts
[ ] 4. Inserir users
[ ] 5. Inserir contract_user (pivot)
[ ] 6. Inserir rules_sharings (se necessário)
[ ] 7. Inserir plans
[ ] 8. Inserir plan_items (em lote)
[ ] 9. Inserir layouts
[ ] 10. Inserir companies
[ ] 11. Inserir company_layout (pivot)
[ ] 12. Inserir peoples (se necessário)
[ ] 13. Inserir people_vinculated (se necessário)
[ ] 14. Para cada empresa/layout:
    [ ] 14a. Inserir imports (status: processing)
    [ ] 14b. Inserir import_sessions (status: pending)
    [ ] 14c. Inserir import_records em lotes de 1000
    [ ] 14d. Chamar CleanImportRecordsForbiddenCharactersService
    [ ] 14e. Atualizar import_sessions.status = 'completed'
    [ ] 14f. Atualizar imports.status = 'completed'
[ ] 15. Inserir rules (se necessário)
[ ] 16. Inserir confrontations (se necessário)
[ ] 17. Inserir confrontation_records (se necessário)
[ ] 18. Inserir exports (se necessário)
```

---

## Referências no Código

| Arquivo                                                                                                                                                   | O que aprender                                      |
| --------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| [`app/Models/Import.php`](../app/Models/Import.php)                                                                                                       | Scopes, cascades, lógica de exclusão                |
| [`app/Models/ImportRecord.php`](../app/Models/ImportRecord.php)                                                                                           | Mutators de account, campo `additional_information` |
| [`app/Models/LayoutCompany.php`](../app/Models/LayoutCompany.php)                                                                                         | Tabela `company_layout`, FK `layout_imp`            |
| [`app/Filament/App/Resources/ImportResource/Actions/CreateImportAction.php`](../app/Filament/App/Resources/ImportResource/Actions/CreateImportAction.php) | Fluxo nativo de criação                             |
| [`app/Jobs/HandleApplyRules.php`](../app/Jobs/HandleApplyRules.php)                                                                                       | Finalização pós-import                              |
| [`app/Services/CleanImportRecordsForbiddenCharactersService.php`](../app/Services/CleanImportRecordsForbiddenCharactersService.php)                       | Limpeza de caracteres                               |
| [`app/Jobs/ProcessOpenFinanceTransactionsJob.php`](../app/Jobs/ProcessOpenFinanceTransactionsJob.php)                                                     | Padrão de bulk insert com `orderedUuid`             |
