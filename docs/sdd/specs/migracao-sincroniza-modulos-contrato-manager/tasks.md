# Tasks: Sincronizar módulos do contrato com o Manager ao finalizar a migração

> Referência: [spec.md](./spec.md)
> Slug: `migracao-sincroniza-modulos-contrato-manager`
> Repositório: `api-hyperf-to-migration` (todos os caminhos são deste repositório)

Ordem sugerida: T1 (baseline) → T2 (serviço) → T3 (orquestrador) → T4 (env + docs) → T5 (validação).
T2 e T4 são independentes entre si; T3 depende de T2. O ganho funcional aparece em T3.

Decisões já tomadas (respostas da spec, 2026-11-17):
- Endpoint: `GET {MANAGER_API_URL}/api/clientes?cnpj=<CNPJ formatado 99.999.999/9999-99>` — só
  dígitos não encontra o cliente (Q1, Q6).
- Autenticação: `POST {MANAGER_API_URL}/api/get-token?secret={MANAGER_SECRET}` → `data.access_token`
  → `Authorization: Bearer` (Q2, P7). Sem cache de token.
- Resposta: `{"api_status": 1, "data": [ {cliente}, … ]}`; usar o item cujo `cnpj` é igual ao
  consultado, senão o primeiro (Q6, RF4).
- `belvo == 1` (`1`, `"1"`, `true`) → `has_open_finance_module = true`; outro valor ou ausente → `false` (Q3).
- `confronto == "Sim"` (trim, case-insensitive) → `has_confrontation_module = true`; outro valor
  presente → `false`; **ausente/`null` → `true`** (obs. de 2026-11-17).
- `api_status != 1`, `data` ausente/não-lista/vazia, HTTP não 2xx, falha de rede/token → erro, sem
  `update` (o contrato fica com os defaults: confronto `true`, open finance `false`).
- O passo roda sempre que `contracts` teve sucesso, mesmo com erro em outras entidades (Q4).
  `company_count` mantém a regra atual (só sem erro nas entidades).
- Sem backfill (Q5).
- Alternativa "estender `ContractCompanyCountSyncService`" descartada (Q1).
- 2026-11-18 (usuário): `empresas_contr` vem na mesma rota `/api/clientes`; centralizar o `company_count` em
  `resolveModules` e remover `ContractCompanyCountSyncService` (D1b, T6). Isso substitui a regra "`company_count`
  só sem erro nas entidades" acima.
- 2026-11-18 (usuário): chave do erro em `error_summary` renomeada de `contract_modules` para `manager_integration` (D1c).
- T5 (2026-11-18, fonte: orquestrador): a parte **opcional** da T5 (migração real em dev chamando o
  Manager de produção com CNPJ real e `MANAGER_SECRET`) **não** é executada nesta rodada — exige
  autorização explícita do usuário, que não foi dada. Nenhuma chamada HTTP ao Manager; fica registrada
  como pendência em `validation.md`. O erro do `composer analyse` em `config/autoload/async_queue.php:36`
  já existia antes desta spec e está fora do escopo.

Testes: rodar só os arquivos alterados/criados, no container `conciliador-migrator`. A extensão
`pgsql` do container está quebrada (`Error relocating …/pgsql.so: smart_str_ferealloc: symbol not
found`); o aviso de startup faz os testes com `#[RunTestsInSeparateProcesses]` falharem. Até a imagem
ser corrigida (fora do escopo), rodar sem o `.ini` do pgsql:

```bash
docker exec conciliador-migrator sh -c 'rm -rf /tmp/confd && mkdir /tmp/confd \
  && cp /etc/php84/conf.d/*.ini /tmp/confd/ && rm -f /tmp/confd/00_pgsql.ini \
  && PHP_INI_SCAN_DIR=/tmp/confd vendor/bin/phpunit -c phpunit.unit.xml.dist <ARQUIVOS>'
```

Análise estática: `docker exec conciliador-migrator sh -c 'PHP_INI_SCAN_DIR=/tmp/confd composer analyse'`.

## T1. Baseline: corrigir a expectativa obsoleta do `MigrationOrchestratorTest`

**Status:** concluída (2026-11-18)
**Notas de execução:** só o teste mudou: nos dois mocks de `ExportLayoutSyncService` (`testRunAbortsWhenContractsEntityFails` e helper `createSuccessfulRunDependencies`) a expectativa `shouldReceive('sync')->once()` virou `shouldNotReceive('sync')`, com comentário de que o sync de layouts de exportação está desligado. Orquestrador intocado. Testes rodados pela trava do orquestrador (já aplica o contorno do pgsql): 9/9 verdes.
**Objetivo:** deixar verde a base de testes que as próximas tasks vão estender, sem mudar código de
produção.
- Situação em 2026-11-17 (comando acima): `ContractCompanyCountSyncServiceTest` 5/5 verdes;
  `MigrationOrchestratorTest` **4/4 com erro**: `Method sync('legacy_connection', 'cont_arenasolucoes')
  from …ExportLayoutSyncService should be called exactly 1 times but called 0 times`. Causa: o commit
  `af7b59a` comentou a chamada em `app/Service/MigrationOrchestrator.php:78-82` ("desabilitado …
  validados manualmente antes da migração"), mas o teste não foi ajustado.
- Em `test/Cases/Unit/Service/MigrationOrchestratorTest.php` (mock em `:84-86` e no helper
  `createSuccessfulRunDependencies`), trocar a expectativa `->shouldReceive('sync')->once()` por
  `->shouldNotReceive('sync')`, documentando no teste que o sync de layouts de exportação está
  desligado. Não reativar a chamada no orquestrador.
- Testes de caracterização que já existem e devem continuar com o mesmo significado (RF8):
  `testRunAbortsWhenContractsEntityFails`, `testRunSyncsContractCompanyCountWhenAllEntitiesCompleteWithoutErrors`,
  `testRunDoesNotSyncContractCompanyCountWhenAnyEntityCompletesWithErrors`,
  `testRunMarksCompletedWithErrorsWhenContractCompanyCountSyncFails`.
**Critério de pronto:** `MigrationOrchestratorTest` 4/4 e `ContractCompanyCountSyncServiceTest` 5/5
verdes com o comando de testes; resultado registrado em `validation.md`.
**Dependências:** nenhuma.
**Cobre Critério de Aceite:** CA7 (parcial — baseline).

## T2. Criar `ContractModulesSyncService` com testes (D1)

**Status:** concluída (2026-11-18)
**Notas de execução:** criados `app/Service/ContractModulesSyncService.php` e `test/Cases/Unit/Service/ContractModulesSyncServiceTest.php` (27 testes). Decisões de implementação: um único `ClientFactory::create(['timeout' => …])` por `sync()`, usado nas duas chamadas (`$client->post`), criado só depois de validar secret, mapping e CNPJ; `postJson()` privado concentra rede/HTTP/JSON e as mensagens levam só a etapa (`token`/`client`), o status HTTP ou a classe da exceção do Guzzle, sem `getMessage()` e **sem encadear a exceção original** (`previous` nulo, porque ela traz a URL com o secret). CNPJ ≠ 14 dígitos → `Contract CNPJ must have exactly 14 digits.` (sem o valor). `api_status` aceito como `1` ou `"1"`; `data` precisa ser lista não vazia (`array_is_list`); item escolhido que não é array → mesmo erro de cliente não retornado. Casos extras além do pedido: fallback para `data[0]` quando nenhum `cnpj` bate, secret ausente além de vazio, `access_token` vazio, `ConnectException` também em `/api/clientes`. O helper `assertSyncFailsWith` checa em toda falha que a mensagem não contém secret, token, e-mail, telefone nem o CNPJ (CA8). Providers devolvem status/corpo (não `Response`), pois objetos com stream não serializam para `RunTestsInSeparateProcesses`. Teste formatado com `php-cs-fixer` (config do projeto). `composer analyse`: 1 erro pré-existente em `config/autoload/async_queue.php:36` (`FailedHandler` não encontrado), nenhum no arquivo novo.
**Objetivo:** serviço novo `app/Service/ContractModulesSyncService.php`, no molde de
`app/Service/ContractCompanyCountSyncService.php` (mesmo header de licença, `declare(strict_types=1)`,
`#[Inject]` de `IdMappingService` e `Hyperf\Guzzle\ClientFactory`, `env()` de `Hyperf\Support`).
- `public function sync(string $migrationScope): void`:
  1. `MANAGER_SECRET` vazio → `RuntimeException('Manager secret is not configured.')` antes de
     qualquer chamada.
  2. `IdMappingService::resolve('contracts', $migrationScope, $migrationScope)`; nulo/vazio →
     `RuntimeException` (mesma mensagem do serviço irmão, `:36-38`).
  3. Ler `cpf_cnpj` em `Db::connection('conciliador_web')->table('contracts')`; nulo → exceção;
     `preg_replace('/\D+/', '', …)`.
  4. `formatCnpj()`; ≠ 14 dígitos → exceção.
  5. `fetchToken()`: `POST {base}/api/get-token`, `query: ['secret' => …]`, `http_errors => false`;
     não 2xx, JSON inválido ou `data.access_token` vazio/não-string → exceção.
  6. `fetchClientPayload()`: `GET {base}/api/clientes` (era POST; corrigido em T7), `query: ['cnpj' => <formatado>]`,
     `headers: ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']`,
     `http_errors => false`; não 2xx ou JSON inválido → exceção.
  7. `pickClient($payload, $cnpjFormatado)`: `api_status` ≠ 1, `data` ausente/não-lista/vazia →
     exceção (`Manager API did not return the client.`); item com `cnpj` igual, senão `data[0]`.
  8. `resolveModules($client)`: retorna `['has_open_finance_module' => bool, 'has_confrontation_module' => bool]`
     conforme as decisões acima.
  9. Um único `update(...)` em `contracts` onde `id = $contractId`.
- Base: `rtrim(env('MANAGER_API_URL', 'https://manager.conciliadorcontabil.com.br'), '/')`, com o
  mesmo ajuste de esquema `https://` do serviço irmão (`:66-68`); timeout `(float) env('MANAGER_API_TIMEOUT', 5.0)`.
- Segurança (RNF2, RNF2b): capturar `Throwable` do Guzzle e lançar mensagem própria **sem**
  `$e->getMessage()` (contém a URL com o secret); nenhuma mensagem inclui corpo da resposta, token,
  secret ou CNPJ. Não logar nada do corpo.
- Teste novo `test/Cases/Unit/Service/ContractModulesSyncServiceTest.php`, no molde de
  `ContractCompanyCountSyncServiceTest.php` (`#[RunTestsInSeparateProcesses]`, `#[PreserveGlobalState(false)]`,
  mock `alias:Hyperf\DbConnection\Db`, `ClientFactory` → `Client` com `request`/`post` conforme a
  implementação, `setEnvValue`). Dados fictícios (CNPJ `11.222.333/0001-81`, sem dados reais). Casos:
  - CA1: `belvo: 1`, `confronto: "Sim"` → `update` com os dois `true`; o `update` recebe exatamente as
    duas chaves.
  - CA2: `belvo: 0`, `confronto: "Não"` → os dois `false`.
  - CA3: dataset `belvo` ∈ {`"1"`, `true`} e `confronto` ∈ {`"sim"`, `" Sim "`} → `true`.
  - CA4: sem `confronto` / `confronto: null` → confronto `true`; sem `belvo` → open finance `false`;
    `data` com dois itens → usa o de `cnpj` igual; `api_status: 0`, `data: []`, sem `data`,
    `data` objeto → exceção e `update` nunca chamado.
  - CA5: secret vazio (nenhuma chamada HTTP), token ausente, HTTP 401 no token, HTTP 404/500 em
    clientes, JSON inválido, Guzzle lança `ConnectException`, contrato sem mapping, `cpf_cnpj` nulo,
    `cpf_cnpj` com 11 dígitos → `RuntimeException`, `update` nunca chamado.
  - CA6b: capturar as opções do `post` de clientes: `query.cnpj === '11.222.333/0001-81'` para
    `cpf_cnpj = '11222333000181'` e header `Authorization === 'Bearer token-fake'`; o `get-token`
    recebe `query.secret` com o valor do env.
  - CA8: com secret `secret-fake`, token `token-fake` e corpo com `email`/`telefone` fictícios,
    nas falhas (incluindo `ConnectException` cuja mensagem contém a URL com o secret) a mensagem da
    exceção não contém nenhum desses valores.
**Critério de pronto:** `ContractModulesSyncServiceTest` todo verde; `ContractCompanyCountSyncServiceTest`
5/5 inalterado; `composer analyse` sem erros novos no arquivo novo.
**Dependências:** T1.
**Cobre Critério de Aceite:** CA1, CA2, CA3, CA4, CA5 (parte do serviço), CA6b, CA8.

## T3. Ligar o serviço no `MigrationOrchestrator` (D2)

**Status:** concluída (2026-11-18)
**Notas de execução:** `MigrationOrchestrator`: injeção de `ContractModulesSyncService`, `$entitiesHadErrors = $errorSummary !== []` logo após o loop, `sync($contractId)` sem condição em `try/catch` próprio (`error_summary['manager_integration']` + `logger->error`), e o bloco do `company_count` passou a usar `if (! $entitiesHadErrors)`; `markCompleted` inalterado. Como o job é abortado no loop quando `contracts` falha, "sem condição" equivale a "sempre que `contracts` teve sucesso" (inclui a retomada com `contracts` já `completed`, idempotente — RF7). Testes: os 3 antigos que chegam ao fim do loop ganharam o mock de módulos (`createSuccessfulModulesSyncService()`, `sync` uma vez); `testRunAbortsWhenContractsEntityFails` injeta os dois serviços com `shouldNotReceive('sync')`. 4 testes novos conforme o plano; os novos conferem o `error_summary` exato. `testRunStillSyncsCompanyCountWhenContractModulesSyncFails` faz o `company_count` também falhar, para provar que os dois passos são independentes e que as duas chaves são registradas (RF6). `php-cs-fixer --dry-run` sem ajustes. `composer analyse`: só o erro já existente em `config/autoload/async_queue.php:36`.
**Objetivo:** em `app/Service/MigrationOrchestrator.php`:
- Injetar `#[Inject] protected ContractModulesSyncService $contractModulesSyncService;` junto das
  outras injeções (`:24-38`).
- Logo após o `foreach` (`:87-142`), guardar `$entitiesHadErrors = $errorSummary !== [];`.
- Chamar `$this->contractModulesSyncService->sync($contractId)` **sem condição**, em `try/catch (Throwable)`
  próprio: na falha, `$errorSummary['manager_integration'] = $e->getMessage();` e
  `$this->logger->error("[job {$jobId}] manager integration failed: " . $e->getMessage());`
  (mensagem já sanitizada pelo serviço — T2).
- Trocar `if (empty($errorSummary))` (`:144`) por `if (! $entitiesHadErrors)` no bloco do
  `company_count`, sem outra mudança nele.
- `markCompleted` (`:153`) inalterado.
- Em `test/Cases/Unit/Service/MigrationOrchestratorTest.php`:
  - Injetar um mock de `ContractModulesSyncService` em todos os testes que chegam ao fim do loop
    (via `injectProperty`, como o `contractCompanyCountSyncService`), para os 4 existentes seguirem com
    o mesmo significado (RF8). Em `testRunAbortsWhenContractsEntityFails`, `shouldNotReceive('sync')`
    nos dois serviços.
  - Novos casos:
    - `testRunSyncsContractModulesWhenAllEntitiesCompleteWithoutErrors` — `sync('cont_arenasolucoes')` uma vez.
    - `testRunSyncsContractModulesEvenWhenAnEntityCompletesWithErrors` — `users` com erro: módulos
      sincronizam, `company_count` não (CA6).
    - `testRunMarksCompletedWithErrorsWhenContractModulesSyncFails` — `markCompleted` com
      `error_summary['manager_integration']` e `markFailed` nunca (CA5).
    - `testRunStillSyncsCompanyCountWhenContractModulesSyncFails` — sem erro nas entidades, módulos
      falham e `company_count` roda mesmo assim (CA6).
**Critério de pronto:** `MigrationOrchestratorTest` todo verde (4 antigos + 4 novos);
`ContractModulesSyncServiceTest` e `ContractCompanyCountSyncServiceTest` seguem verdes;
`composer analyse` sem erros novos.
**Dependências:** T2.
**Cobre Critério de Aceite:** CA5 (parte do orquestrador), CA6, CA7.

## T4. Env e documentação (D1, D3)

**Status:** concluída (2026-11-18)
**Notas de execução:** `.env.example`: abaixo de `MANAGER_API_TIMEOUT`, `MANAGER_API_URL=https://manager.conciliadorcontabil.com.br` e `MANAGER_SECRET=` (vazio), com comentários (mesmo secret do conciliadorweb; vazio faz o passo falhar em `error_summary['manager_integration']`). `docs/ai/architecture.md`: no diagrama do passo 5, `ExportLayoutSyncService::sync()` virou nota de desligado e entraram `ContractModulesSyncService::sync()` (sempre que `contracts` teve sucesso) e `ContractCompanyCountSyncService::sync()` (só sem erro nas entidades); abaixo do diagrama, 3 itens com endpoints, envs, regra de mapeamento e chaves do `error_summary`. `.env`/`.env.prod` intocados; `git diff` conferido: nenhum valor de secret/token (só o placeholder `{MANAGER_SECRET}` no doc). Nenhum outro arquivo de `docs/ai/` lista essas envs. Sem testes (só docs/env).
**Objetivo:**
- `.env.example`: abaixo de `MANAGER_API_TIMEOUT` (`:85`), adicionar
  `MANAGER_API_URL=https://manager.conciliadorcontabil.com.br` e `MANAGER_SECRET=` (vazio, com
  comentário de que é o mesmo secret do conciliadorweb). Não tocar em `.env`/`.env.prod` (o valor real
  é configurado no deploy; não escrever o secret em nenhum arquivo versionado).
- `docs/ai/architecture.md`, diagrama do passo 5 (`:183-193`): trocar `ExportLayoutSyncService::sync()`
  por uma nota de que está desligado, e acrescentar depois do loop
  `ContractModulesSyncService::sync()` (sempre que `contracts` teve sucesso) e
  `ContractCompanyCountSyncService::sync()` (só sem erro nas entidades), com as envs e a regra de
  mapeamento em 3-4 linhas logo abaixo do diagrama.
**Critério de pronto:** `.env.example` e `docs/ai/architecture.md` atualizados; nenhum segredo em
arquivo versionado (`git diff` conferido).
**Dependências:** nenhuma (pode rodar junto com T2).
**Cobre Critério de Aceite:** — (suporte a D1/D3).

## T5. Validação final

**Status:** concluída (2026-11-18)
**Notas de execução:** os 3 arquivos de teste juntos → OK (40 tests, 230 assertions): 27 + 8 + 5. `composer analyse` → só o erro já existente em `config/autoload/async_queue.php:36`. Colunas conferidas no `conciliador_web` de dev por consulta só de leitura em `information_schema.columns`: `has_confrontation_module` boolean NOT NULL default `true` e `has_open_finance_module` boolean NOT NULL default `false`. Parte opcional (migração real com o Manager de produção) **não executada** por decisão do orquestrador (sem autorização do usuário); nenhuma chamada HTTP ao Manager; registrada como pendência em `validation.md`, junto com as pendências de deploy (migration `5cd39241` antes; `MANAGER_API_URL`/`MANAGER_SECRET` no ambiente). Tabela de resultado por CA em `validation.md`.
**Objetivo:** não há tela; a validação é por testes e, se o usuário autorizar, por uma migração real em dev.
- Rodar juntos `ContractModulesSyncServiceTest`, `MigrationOrchestratorTest` e
  `ContractCompanyCountSyncServiceTest`, mais `composer analyse`; registrar números em `validation.md`.
- Conferir no banco `conciliador_web` de dev que as colunas `has_confrontation_module` /
  `has_open_finance_module` existem (dependência de deploy P4; a migration é do conciliadorweb,
  commit `5cd39241`).
- **Opcional, só com autorização explícita do usuário** (chama o Manager de produção com CNPJ real e
  exige `MANAGER_SECRET` no `.env` local): disparar uma migração de um banco legado pequeno em dev,
  conferir no `GET /api/v1/migration/job/{id}` o status/`error_summary` e, no `conciliador_web`, as
  duas flags do contrato. Não registrar CNPJ, secret nem corpo da resposta em `validation.md`.
- Registrar em `validation.md` a pendência de deploy: migration `5cd39241` do conciliadorweb em
  produção **antes** deste deploy, e `MANAGER_API_URL`/`MANAGER_SECRET` configurados no ambiente.
**Critério de pronto:** CA1–CA8 verificados por teste e registrados; pendências de deploy anotadas.
**Dependências:** T1–T4.
**Cobre Critério de Aceite:** todos.

## T6. Centralizar `company_count` na chamada a `/api/clientes` (D1b)

**Status:** concluída (2026-11-18)
**Notas de execução:** `ContractModulesSyncService::resolveModules()` lê `empresas_contr` (int ou string numérica → `(int)`) e inclui `company_count` no mesmo `update`; ausente/`null`/não numérico → grava só os módulos e lança `Manager API field 'empresas_contr' must be numeric.` (vira `error_summary['manager_integration']`). `MigrationOrchestrator`: removidos a injeção de `ContractCompanyCountSyncService`, `$entitiesHadErrors` e o bloco do `company_count`. Removidos `app/Service/ContractCompanyCountSyncService.php`, `test/Cases/Unit/Service/ContractCompanyCountSyncServiceTest.php` e `MANAGER_API_BASE_URL` do `.env.example`. A alteração local não commitada no serviço removido (host padrão `managerv2api` → `manager`) ficou sem efeito. Testes: `ContractModulesSyncServiceTest` com `company_count` nos `update` esperados + 4 casos novos (string numérica; ausente, `null`, texto); `MigrationOrchestratorTest` sem os 4 testes do passo removido (ficam 4). `docs/ai/architecture.md` atualizado.
**Objetivo:** uma só chamada ao Manager grava `has_open_finance_module`, `has_confrontation_module` e `company_count`.
**Dependências:** T1–T5.
**Cobre Critério de Aceite:** CA6, CA7 (revistos).

## T7. `/api/clientes` via GET (D1d) + validação ponta a ponta

**Status:** concluída (2026-11-19)
**Notas de execução:** a migração real de `cont_welingtonmenezes` em dev terminou `completed_with_errors` com `manager_integration: Manager API did not return the client.` e nada gravado. Diagnóstico direto do container (só estrutura da resposta, sem dados pessoais): `get-token` OK; `/api/clientes` com POST (query, form, multipart) → HTTP 200, `api_status: 0`, "The requested method is not allowed!"; com GET + CNPJ formatado na query → `api_status: 1`, 1 item com `cnpj` igual. `ContractModulesSyncService`: `postJson()` virou `requestJson($client, $method, …)` com `Client::request()`; `get-token` em POST, `/api/clientes` em GET. Teste: o mock passou a `request` (com `shouldNotReceive('post', 'get')`) e o teste principal confere o método de cada chamada. Reexecução do job (`php bin/hyperf.php migration:run-job <job>`, entidades concluídas puladas) → contrato com `has_open_finance_module = true`, `has_confrontation_module = true`, `company_count = 62`; job `completed`. Achado fora do escopo: `MigrationJobService::markCompleted()` só grava `error_summary` quando não é `null`, então o erro da execução anterior continua no job reexecutado sem erro.
**Dependências:** T6.
**Cobre Critério de Aceite:** CA1, CA6b (método), validação ponta a ponta pendente da T5.
