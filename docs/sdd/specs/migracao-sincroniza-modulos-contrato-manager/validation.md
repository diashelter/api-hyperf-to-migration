# Validation: Sincronizar módulos do contrato com o Manager ao finalizar a migração

> Referência: [spec.md](./spec.md) · [tasks.md](./tasks.md)

## Medições de referência (antes)

Sem metas de desempenho nesta spec. Baseline de testes em 2026-11-17: `ContractCompanyCountSyncServiceTest`
5/5 verdes; `MigrationOrchestratorTest` 4/4 com erro (`sync` de `ExportLayoutSyncService` esperado 1 vez,
chamado 0 — chamada comentada no orquestrador pelo commit `af7b59a`).

## Execução

### T1 — 2026-11-18
- Testes: `vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/MigrationOrchestratorTest.php test/Cases/Unit/Service/ContractCompanyCountSyncServiceTest.php`
  (no container `conciliador-migrator`, sem o `.ini` do pgsql, via trava do orquestrador) → **OK (9 tests, 13 assertions)**:
  `MigrationOrchestratorTest` 4/4 e `ContractCompanyCountSyncServiceTest` 5/5.
- Observações: só `test/Cases/Unit/Service/MigrationOrchestratorTest.php` alterado (expectativa do
  `ExportLayoutSyncService::sync` passou a `shouldNotReceive`). Os 4 testes de caracterização mantêm o
  significado (RF8). CA7 parcial (baseline).

### T2 — 2026-11-18
- Testes: `vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/ContractModulesSyncServiceTest.php test/Cases/Unit/Service/ContractCompanyCountSyncServiceTest.php`
  (via trava do orquestrador) → **OK (32 tests, 222 assertions)**: `ContractModulesSyncServiceTest` 27/27,
  `ContractCompanyCountSyncServiceTest` 5/5 (inalterado).
- Cobertura por CA no serviço: CA1 (`belvo: 1`/`"Sim"` → `update` com exatamente as duas chaves `true`),
  CA2 (`0`/`"Não"` → `false`), CA3 (`"1"`/`true`, `"sim"`/`" Sim "`), CA4 (`confronto` ausente/`null` → `true`;
  `belvo` ausente → `false`; dois itens → o de `cnpj` igual; fallback `data[0]`; `api_status: 0`, `data: []`,
  sem `data`, `data` objeto → exceção sem `update`), CA5 (secret ausente/vazio sem chamada HTTP, token
  ausente/vazio, HTTP 401 no token, JSON inválido no token e em clientes, HTTP 404/500 em clientes,
  `ConnectException` nas duas chamadas, sem mapping, `cpf_cnpj` nulo, 11 dígitos), CA6b (`query.secret`,
  `query.cnpj = 11.222.333/0001-81` para `11222333000181`, `Authorization: Bearer token-fake`, base sem esquema
  normalizada para `https://`, timeout do env), CA8 (toda falha: mensagem sem secret, token, e-mail, telefone ou
  CNPJ, e sem exceção encadeada).
- `composer analyse` → 1 erro, pré-existente e fora do escopo (`config/autoload/async_queue.php:36`,
  `Hyperf\AsyncQueue\Handler\FailedHandler` não encontrado); nenhum erro em `app/Service/ContractModulesSyncService.php`.
- `php-cs-fixer --dry-run` (config do projeto): serviço sem ajustes; teste reordenado pelo fixer e testes
  re-rodados depois (mesmo resultado).

### T3 — 2026-11-18
- Testes: `vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/MigrationOrchestratorTest.php test/Cases/Unit/Service/ContractModulesSyncServiceTest.php test/Cases/Unit/Service/ContractCompanyCountSyncServiceTest.php`
  (via trava do orquestrador) → **OK (40 tests, 230 assertions)**: `MigrationOrchestratorTest` 8/8 (4 antigos com o
  mesmo significado + 4 novos), `ContractModulesSyncServiceTest` 27/27, `ContractCompanyCountSyncServiceTest` 5/5.
- CAs no orquestrador: CA5 (falha nos módulos → `markCompleted` com `['manager_integration' => …]`, `markFailed` nunca),
  CA6 (entidade `users` com erro → módulos sincronizam e `company_count` não; módulos falham sem erro nas entidades →
  `company_count` roda; `contracts` em falha → nenhum dos dois roda), CA7 (antigos verdes).
- `composer analyse` → 1 erro, que já existia antes desta spec e está fora do escopo (decisão do orquestrador em
  2026-11-18): `config/autoload/async_queue.php:36`, `Hyperf\AsyncQueue\Handler\FailedHandler` não encontrado. Nenhum
  erro em `app/Service/MigrationOrchestrator.php` nem em `app/Service/ContractModulesSyncService.php`.
- `php-cs-fixer --dry-run` (config do projeto) nos dois arquivos tocados: 0 ajustes.

### T4 — 2026-11-18
- Testes: não se aplica (só `.env.example` e `docs/ai/architecture.md`).
- Conferências: `git diff` sem valor de secret/token (o doc usa só o placeholder `{MANAGER_SECRET}`); `.env` e
  `.env.prod` fora do diff; `MANAGER_SECRET=` vazio no `.env.example`.

### T5 — 2026-11-18 (validação final)
- Testes (os 3 arquivos juntos, via trava do orquestrador, container `conciliador-migrator` sem o `.ini` do pgsql):
  `vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/ContractModulesSyncServiceTest.php test/Cases/Unit/Service/MigrationOrchestratorTest.php test/Cases/Unit/Service/ContractCompanyCountSyncServiceTest.php`
  → **OK (40 tests, 230 assertions)**: `ContractModulesSyncServiceTest` 27/27, `MigrationOrchestratorTest` 8/8,
  `ContractCompanyCountSyncServiceTest` 5/5.
- `composer analyse` (114 arquivos) → 1 erro, já existente antes desta spec e fora do escopo (decisão do orquestrador):
  `config/autoload/async_queue.php:36`, `Hyperf\AsyncQueue\Handler\FailedHandler` não encontrado. Nenhum erro em
  `app/Service/ContractModulesSyncService.php` nem em `app/Service/MigrationOrchestrator.php`.
- Banco `conciliador_web` de dev (conexão `conciliador_web` do container, base `conciliador`), consulta só de leitura em
  `information_schema.columns` da tabela `contracts`:

  | Coluna | Tipo | Nulo | Default |
  |---|---|---|---|
  | `has_confrontation_module` | boolean | NOT NULL | `true` |
  | `has_open_finance_module` | boolean | NOT NULL | `false` |

  Bate com a migration `2026_11_17_130000_add_unlimited_and_module_flags_to_contracts_table.php` do conciliadorweb
  (commit `5cd39241`), aplicada no dev.
- `git diff` conferido de novo: nenhum valor de secret/token em arquivo versionado (`MANAGER_SECRET=` vazio no
  `.env.example`; só o placeholder `{MANAGER_SECRET}` em `docs/ai/architecture.md`).

### T6 — `company_count` centralizado (2026-11-18)
- `vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/ContractModulesSyncServiceTest.php test/Cases/Unit/Service/MigrationOrchestratorTest.php`
  → OK (35 tests, 253 assertions): 31 do serviço (27 com `company_count` no `update` esperado + 4 novos) e 4 do orquestrador.
- `composer analyse` → só o erro já existente em `config/autoload/async_queue.php:36`. `php-cs-fixer --dry-run` sem ajustes.
- CA6/CA7 revistos (D1b): `ContractCompanyCountSyncServiceTest` removido junto com o serviço; a linha CA6/CA7 da tabela
  abaixo descreve a rodada de T5, antes da centralização.

### T7 — GET em `/api/clientes` + ponta a ponta (2026-11-19)
- Ponta a ponta com o Manager de produção, autorizado pelo usuário, base `cont_welingtonmenezes` em dev:
  - antes da correção: job `completed_with_errors`, `manager_integration: Manager API did not return the client.`; contrato
    com os defaults (`company_count = 0`, open finance `false`, confronto `true`). Causa: POST em `/api/clientes` → `api_status: 0`
    ("The requested method is not allowed!"); GET → cliente retornado.
  - depois (job reexecutado com `migration:run-job`): `has_open_finance_module = true`, `has_confrontation_module = true`,
    `company_count = 62`; job `completed`. O `error_summary` antigo permanece (comportamento existente de `markCompleted`).
- `vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/ContractModulesSyncServiceTest.php test/Cases/Unit/Service/MigrationOrchestratorTest.php`
  → OK (35 tests, 255 assertions).
- A pendência "validação ponta a ponta com o Manager real" abaixo fica resolvida por esta entrada.

#### Medições finais / resultado por CA

| CA | Verificação | Resultado |
|---|---|---|
| CA1 | `ContractModulesSyncServiceTest`: `belvo: 1` + `confronto: "Sim"` → `update` só com as duas chaves `true` no id mapeado | passou |
| CA2 | `belvo: 0` + `confronto: "Não"` → as duas `false` | passou |
| CA3 | `belvo` `"1"`/`true`, `confronto` `"sim"`/`" Sim "` → `true` | passou |
| CA4 | `confronto` ausente/`null` → `true`; `belvo` ausente → `false`; dois itens → o de `cnpj` igual (fallback `data[0]`); `api_status: 0`, `data: []`, sem `data`, `data` objeto → exceção sem `update` | passou |
| CA5 | Serviço: secret ausente/vazio (sem HTTP), token ausente/vazio, HTTP 401/404/500, JSON inválido, `ConnectException`, sem mapping, `cpf_cnpj` nulo, 11 dígitos → `RuntimeException` sem `update`. Orquestrador: `completed_with_errors` com `error_summary['manager_integration']`, `markFailed` nunca | passou |
| CA6 | `MigrationOrchestratorTest`: entidade `users` com erro → módulos rodam, `company_count` não; módulos falham sem erro nas entidades → `company_count` roda; `contracts` em falha → nenhum dos dois | passou |
| CA6b | Opções do `post`: `query.secret` do env no `get-token`; `query.cnpj = 11.222.333/0001-81` para `11222333000181`; `Authorization: Bearer token-fake` | passou |
| CA7 | 4 testes antigos de `MigrationOrchestratorTest` e 5 de `ContractCompanyCountSyncServiceTest` verdes; `composer analyse` sem erros novos | passou |
| CA8 | Toda falha do serviço: mensagem sem secret, token, e-mail, telefone ou CNPJ, e sem exceção encadeada | passou |

#### Pendências
- **Validação ponta a ponta com o Manager real: não executada, aguardando autorização do usuário** (migração de um
  banco legado pequeno em dev, chamando o Manager de produção com CNPJ real e `MANAGER_SECRET` no `.env` local;
  conferir `GET /api/v1/migration/job/{id}` e as duas flags no `conciliador_web`). Nenhuma chamada ao Manager foi
  feita nesta spec.
- **Deploy (ordem obrigatória):**
  1. A migration `2026_11_17_130000_add_unlimited_and_module_flags_to_contracts_table.php` (commit `5cd39241` do
     conciliadorweb, hoje só na branch `fix/upload-ofx-tipo-arquivo-invalido-importacao`) precisa estar aplicada no
     `conciliador_web` de produção **antes** do deploy deste hyperf; sem ela todo job termina `completed_with_errors`
     com `error_summary['manager_integration']`.
  2. Configurar `MANAGER_API_URL` (padrão `https://manager.conciliadorcontabil.com.br`) e `MANAGER_SECRET` (mesmo do
     conciliadorweb) no ambiente do hyperf; `MANAGER_SECRET` vazio faz o passo falhar em todo job.
- Fora do escopo: extensão `pgsql` quebrada na imagem do container (contorno do `.ini`) e o erro do `composer analyse`
  em `config/autoload/async_queue.php:36`.
