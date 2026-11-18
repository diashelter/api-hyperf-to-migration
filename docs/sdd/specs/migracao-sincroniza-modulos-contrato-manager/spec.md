# Spec: Sincronizar módulos do contrato (Confronto / Open Finance) com o Manager ao finalizar a migração (`api-hyperf-to-migration`)

> Status: proposto — questões 1–6 respondidas em 2026-11-17
> Slug: `migracao-sincroniza-modulos-contrato-manager`
> Data da investigação: 2026-11-17
> Repositório: **`api-hyperf-to-migration`** (Hyperf). Os caminhos `app/...`/`test/...` são deste
> repositório; os prefixados com `conciliadorweb:` são de `/var/www/conciliadorweb`.
> Spec irmã: `conciliadorweb:docs/sdd/specs/contrato-usuarios-limites-ilimitados-e-modulos` (criou as
> colunas `has_confrontation_module` / `has_open_finance_module` e os toggles no formulário de Contrato).

## Contexto

O conciliadorweb dispara a migração de um banco legado pelo painel Admin
(`conciliadorweb:app/Actions/Admin/Migration/StartMigrationAction.php` →
`conciliadorweb:app/Services/MigrationDatabase/MigrationApiService.php`, `POST /api/v1/migration/database`)
e acompanha o status do job (`CheckMigrationStatusAction` → `GET /api/v1/migration/job/{id}`). Toda a
migração roda no hyperf, em `MigrationOrchestrator::run()`.

Pedido do usuário: ao finalizar a migração, consultar o Manager
(`POST https://manager.conciliadorcontabil.com.br/api/clientes?cnpj=XXXXXXXXXX`) pelo CNPJ do contrato
e gravar no contrato do conciliador_web:

| Campo do Manager | Valor | Coluna em `contracts` |
|---|---|---|
| `belvo` | `1` | `has_open_finance_module = true` |
| `confronto` | `"Sim"` | `has_confrontation_module = true` |

Decisão já tomada (conversa de 2026-11-17): o passo fica **no hyperf, dentro do fluxo da migração**, e
não como "pós-migração" no conciliadorweb (motivos em P1 e P2).

Escopo: novo passo pós-migração no orquestrador do hyperf + testes. Não muda tela, nem o
conciliadorweb, nem as entidades migradas.

## Investigação

Leitura de código nos dois repositórios (hyperf em `c02dbb9`; conciliadorweb na branch
`fix/upload-ofx-tipo-arquivo-invalido-importacao`, `9bf70db8`). Não se aplica medição de navegador ou
SQL: é uma integração de backend sem tela. **O endpoint do Manager não foi chamado**: é um `POST` num
sistema externo de produção, com CNPJ real, e o formato da resposta ficou como questão em aberto (Q1).

### Pontos levantados

**P1. Já existe um passo pós-migração que chama o Manager pelo CNPJ, e este é o molde a seguir.**
`app/Service/MigrationOrchestrator.php:143-150`: depois de iterar todas as entidades, se
`$errorSummary` está vazio, roda `ContractCompanyCountSyncService::sync($contractId)` dentro de um
`try/catch`; uma falha vira `$errorSummary['contract_company_count']` e o job termina
`completed_with_errors` (`MigrationJobService::markCompleted`, `app/Service/MigrationJobService.php:100-121`).
`app/Service/ContractCompanyCountSyncService.php`:
- `:33-38` resolve o id do contrato no destino via `IdMappingService::resolve('contracts', $scope, $scope)`;
- `:40-53` lê `contracts.cpf_cnpj` em `Db::connection('conciliador_web')` e remove tudo que não é dígito;
- `:64-79` chama o Manager com `Hyperf\Guzzle\ClientFactory` (`http_errors => false`), base em
  `env('MANAGER_API_BASE_URL', 'https://managerv2api.conciliadorcontabil.com.br')` e timeout em
  `env('MANAGER_API_TIMEOUT', 5.0)`;
- `:81-104` valida HTTP 2xx, JSON e o campo esperado, lançando `RuntimeException` em qualquer desvio;
- `:55-58` faz `update` em `contracts` (idempotente).
Testes: `test/Cases/Unit/Service/ContractCompanyCountSyncServiceTest.php` (5 casos, mocks de
`IdMappingService`, `alias:Hyperf\DbConnection\Db`, `ClientFactory`/`Client`) e
`test/Cases/Unit/Service/MigrationOrchestratorTest.php:111-190` (sincroniza quando não há erro, não
sincroniza com erro, `completed_with_errors` quando o sync falha).

**P2. O conciliadorweb não sabe sozinho que a migração terminou.** O status só é atualizado quando um
admin clica em "Atualizar Status" (`conciliadorweb:app/Filament/Admin/Resources/DatabaseMigrationResource/Pages/ViewDatabaseMigration.php:21-41`);
não há scheduler nem webhook. Um pós-migração no web dependeria desse clique.

**P3. O endpoint pedido é outro.** O sync atual faz `GET {MANAGER_API_BASE_URL}/api/public/clientes/cnpj?cnpj=`
(host padrão `managerv2api.…`) e lê `empresas_contr`. O pedido é `POST manager.conciliadorcontabil.com.br/api/clientes?cnpj=`.
Host, método e caminho diferentes → não dá para reaproveitar a chamada atual sem confirmar (Q1).
O conciliadorweb tem `conciliadorweb:config/manager.php` com `MANAGER_API_URL` (= `https://manager.conciliadorcontabil.com.br`
no `.env.example:81`) e `MANAGER_SECRET`, **sem nenhum uso no código** — indício de que esse host
pode exigir autenticação (Q2).

**P4. As colunas de destino existem só numa branch local do conciliadorweb.**
`conciliadorweb:database/migrations/2026_11_17_130000_add_unlimited_and_module_flags_to_contracts_table.php`
(commit `5cd39241`) cria `has_confrontation_module boolean NOT NULL DEFAULT true` e
`has_open_finance_module boolean NOT NULL DEFAULT false`. Hoje esse commit só está em
`fix/upload-ofx-tipo-arquivo-invalido-importacao` (`git branch -a --contains 5cd39241`). Se o hyperf
for para produção antes dessa migration, o `update` falha com coluna inexistente.

**P5. `has_confrontation_module` nasce `true`.** O contrato migrado é inserido por
`app/PullMode/Source/ContractSource.php` sem essas colunas, então recebe os defaults do banco
(`true` / `false`). Gravar **só o lado positivo** do pedido ("Sim" → `true`) não muda nada para
Confronto: o valor já é `true`. Para a flag refletir o Manager, `confronto != "Sim"` precisa gravar
`false` (Q3).

**P6. O sync atual só roda se nenhuma entidade teve erro** (`MigrationOrchestrator.php:143`). Os
módulos dependem só do contrato existir no destino (entidade `contracts`, que já aborta o job
quando falha — `:156-163`). Colocar o novo passo sob o mesmo `if` deixaria de gravar os módulos
quando, por exemplo, uma única `import_records` falhar (Q4).

**P7. Autenticação no Manager (`manager.conciliadorcontabil.com.br`) já tem precedente.** Resposta da
Q2: seguir o `LayoutAdminSeeder` como era antes do commit `ee0cb1c9` do conciliadorweb
(`git show ee0cb1c9^:database/seeders/LayoutAdminSeeder.php`, linhas 13-17):
1. `POST {MANAGER_API_URL}/api/get-token?secret={MANAGER_SECRET}` → token em `data.access_token`;
2. chamadas seguintes com `Authorization: Bearer {token}` (`Http::withToken`);
3. o endpoint de layouts devolve o payload dentro de `data` (`->json()['data']`).
Hoje nenhum código do conciliadorweb usa mais esse fluxo (`grep get-token app/` vazio); o
`config/manager.php` (`api_url`, `secret`) ficou dessa época.

## Objetivo

Ao finalizar a migração, o contrato no conciliador_web fica com "Usa módulo Confronto" e "Usa Open
Finance" conforme o cadastro do cliente no Manager, sem que uma falha do Manager derrube a migração
nem afete o `company_count`.

## Requisitos

### Funcionais

- **RF1.** Ao fim de `MigrationOrchestrator::run()`, sempre que a entidade `contracts` terminou com
  sucesso — **independentemente** de erro em outras entidades (Q4) —, o hyperf obtém o token
  (P7) e faz `POST /api/clientes?cnpj=` no Manager com o CNPJ do contrato de destino **formatado**
  (`99.999.999/9999-99`). O Manager não encontra o cliente com o CNPJ só em dígitos (Q6).
  `contracts.cpf_cnpj` guarda só dígitos (`ContractSource.php:61`); valor sem exatamente 14 dígitos
  → erro (RF5).
- **RF2.** `belvo` igual a `1` (aceitar `1`, `"1"` e `true`) → `has_open_finance_module = true`;
  qualquer outro valor → `false` (Q3).
- **RF3.** `confronto` igual a `"Sim"` (comparação sem diferenciar maiúsculas e ignorando espaços nas
  pontas) → `has_confrontation_module = true`; qualquer outro valor **presente** → `false` (Q3).
  Sem retorno do Manager, `has_confrontation_module` fica **`true`**, que é o padrão (obs. de
  2026-11-17): campo `confronto` ausente ou `null` → `true`; falha da chamada ou cliente não
  encontrado → nenhum `update`, e o contrato recém-migrado mantém o default do banco (`true`, P4/P5).
- **RF4.** Os campos são lidos do cliente em `data[]` (Q6). Escolha do cliente: o item de `data`
  cujo `cnpj` é igual ao CNPJ formatado consultado; se nenhum bater, o primeiro item. `belvo`
  ausente ou `null` → `false` (default da coluna); `confronto` ausente ou `null` → `true` (default
  da coluna, RF3). Um único `update` grava as
  duas colunas. `api_status != 1`, `data` ausente, que não é lista ou vazio → erro (RF5), sem
  `update` (senão um "não encontrado" gravaria as duas flags `false`).
- **RF5.** Falha (token não obtido, rede, timeout, HTTP não 2xx, JSON inválido, resposta sem cliente
  conforme RF4, contrato sem mapeamento ou sem CNPJ válido)
  não interrompe a migração: vira `error_summary['contract_modules']` com a mensagem, e o job
  termina `completed_with_errors`.
- **RF6.** O passo é independente do sync de `company_count`: a falha de um não impede o outro.
- **RF7.** Idempotente: rodar de novo (retomada do job) produz o mesmo estado, sem efeitos extras.
- **RF8.** Comportamento atual preservado: `ContractCompanyCountSyncService` e sua regra (só roda
  quando nenhuma entidade teve erro) não mudam; os 4 testes de `MigrationOrchestratorTest` seguem
  verdes sem mudar de significado.

### Não-funcionais

- **RNF1.** Timeout da chamada configurável por env, padrão igual ao sync atual (5 s); o passo
  adiciona no máximo esse tempo ao fim do job.
- **RNF2.** Nenhum segredo (`MANAGER_SECRET`, `access_token`) em log, `error_summary` ou notificação
  do Discord — atenção à URL do `get-token`, que leva o secret na query string: mensagens de erro do
  Guzzle incluem a URL e não podem ser repassadas cruas.
- **RNF2b.** O corpo de `/api/clientes` traz dados pessoais do cliente (e-mail, telefone, endereço,
  razão social com nome de pessoa física — Q6). Nunca logar nem gravar o corpo da resposta, nem
  trechos dele, em log, `error_summary` ou Discord; o serviço só extrai `belvo` e `confronto`.
- **RNF3.** Cobertura por testes unitários (PHPUnit + Mockery), no padrão de
  `ContractCompanyCountSyncServiceTest`, sem chamar o Manager real.

## Design (direção)

### D1. Novo `ContractModulesSyncService` (resolve RF1–RF5, RF7; RNF1–RNF3)

- `app/Service/ContractModulesSyncService.php`, método `sync(string $migrationScope): void`, no molde
  de `ContractCompanyCountSyncService` (P1): resolve o contrato via `IdMappingService`, lê
  `cpf_cnpj` em `conciliador_web`, autentica e chama o Manager com `ClientFactory`
  (`http_errors => false`), valida e faz um único `update` em `contracts` com as duas colunas (RF4).
- Autenticação conforme P7, em duas chamadas no mesmo `sync()`:
  `POST {MANAGER_API_URL}/api/get-token` com `query: ['secret' => …]` → `data.access_token` (string
  não vazia, senão `RuntimeException`); depois
  `POST {MANAGER_API_URL}/api/clientes` com `query: ['cnpj' => <CNPJ formatado>]` e header
  `Authorization: Bearer {token}`. Sem cache do token: é uma chamada por migração.
- Formatação do CNPJ num método privado puro (`formatCnpj(string $digits): string`, máscara
  `%s.%s.%s/%s-%s` sobre 2-3-3-4-2 dígitos); menos ou mais de 14 dígitos → `RuntimeException`.
  O Guzzle codifica `/` na query (`%2F`); o Manager aceita, como na URL de exemplo.
- Extração do cliente (`pickClient(array $payload, string $cnpj): array`, RF4) e mapeamento
  (`resolveModules(array $client): array`) em métodos privados puros, para testar formatos e
  valores sem HTTP.
- Configuração por env, com os mesmos nomes do conciliadorweb (`config/manager.php`) e sem
  reaproveitar `MANAGER_API_BASE_URL` (host diferente, P3): `MANAGER_API_URL` (padrão
  `https://manager.conciliadorcontabil.com.br`), `MANAGER_SECRET` (sem padrão; vazio →
  `RuntimeException` antes de qualquer chamada) e `MANAGER_API_TIMEOUT` (já existe, padrão `5.0`).
  Adicionar `MANAGER_API_URL` e `MANAGER_SECRET` (vazio) ao `.env.example` do hyperf.
- Alternativa descartada (2026-11-17, Q1): estender `ContractCompanyCountSyncService` — o endpoint
  é o novo (`/api/clientes`), não o `/api/public/clientes/cnpj` atual.
- Erros em `RuntimeException` com mensagem curta em inglês (padrão do arquivo irmão), sem corpo da
  resposta (nem trecho — RNF2b) e sem a mensagem crua do Guzzle (traz a URL com o secret — RNF2).
  Diferente do `DatabaseConnectionService::summarizeBody` do conciliadorweb, que loga 500 caracteres.

### D2. Ligar no orquestrador (resolve RF5, RF6, RF8; P6)

- Injetar `#[Inject] protected ContractModulesSyncService $contractModulesSyncService;` em
  `MigrationOrchestrator`.
- Chamar em `try/catch` próprio, separado do `company_count`, gravando
  `$errorSummary['contract_modules']` na falha, antes de `markCompleted` (`MigrationOrchestrator.php:153`).
- Condição (Q4): basta `contracts` ter sucesso. Como falha em `contracts` já retorna antes
  (`MigrationOrchestrator.php:121-126` e `:135-140`), chegar ao fim do loop garante isso — inclusive
  na retomada, em que `contracts` é pulada por já estar `completed` (`:93-95`). Logo, o passo roda
  **sempre** depois do loop, sem `if`.
- O `company_count` mantém o `if` atual. Para os dois passos não dependerem de ordem, calcular
  `$entitiesHadErrors = $errorSummary !== []` logo após o loop e usar no `if` do `company_count`;
  assim uma falha de módulos (gravada em `$errorSummary`) não pula o `company_count`.

### D3. Documentação do hyperf

- Atualizar `docs/ai/workflows.md` (ou `architecture.md`, onde o passo de `company_count` estiver
  descrito) com o novo passo e as envs.

## Não-objetivos

- Bloquear ou liberar módulos no painel App do conciliadorweb a partir das flags.
- Backfill dos contratos já migrados (Q5: não precisa).
- Mudar o sync de `company_count` ou o endpoint que ele usa.
- Notificação específica no Discord para esse passo (o `error_summary` já aparece no painel Admin).

## Critérios de aceite

1. **CA1** (RF1, RF2, RF3, RF4). Manager responde no formato da Q6 com
   `data: [{"cnpj": "11.222.333/0001-81", "belvo": 1, "confronto": "Sim", …}]` → `update` em
   `contracts` com `has_open_finance_module = true` e `has_confrontation_module = true` no id mapeado.
2. **CA2** (RF2, RF3). Cliente com `belvo: 0` e `confronto: "Não"` → as duas colunas `false`.
3. **CA3** (RF2, RF3). `belvo` como `"1"` ou `true` e `confronto` como `"sim"`/`" Sim "` → `true`.
4. **CA4** (RF3, RF4). Cliente só com `belvo: 1` (sem `confronto`, ou `null`) → `has_open_finance_module = true`
   e `has_confrontation_module = true`. Cliente só com `confronto: "Não"` (sem `belvo`) →
   as duas `false`. `data` com dois itens → usa o de `cnpj` igual ao consultado.
   `api_status: 0`, `data: []`, `data` ausente ou não-lista → nenhum `update` e exceção (contrato
   fica com `has_confrontation_module = true`, do default).
5. **CA5** (RF5). `MANAGER_SECRET` vazio, `get-token` sem `data.access_token`, HTTP 401/404/500,
   timeout, JSON inválido, contrato sem mapeamento, sem CNPJ ou com CNPJ ≠ 14 dígitos →
   `RuntimeException`; no orquestrador, job `completed_with_errors` com `error_summary['contract_modules']`.
6. **CA6** (RF1, RF6). Com uma entidade não-`contracts` em erro, o sync de módulos **roda** e o de
   `company_count` **não**; falha no sync de módulos não impede o `company_count` quando não há erro
   nas entidades; com `contracts` em falha, nenhum dos dois roda.
7. **CA6b** (RF1). A chamada a `/api/clientes` leva `Authorization: Bearer <token do get-token>` e
   `cnpj=11.222.333/0001-81` (formatado) para `cpf_cnpj = 11222333000181`.
8. **CA7** (RF8). Testes existentes de `ContractCompanyCountSyncServiceTest` e `MigrationOrchestratorTest`
   verdes; `composer analyse` sem erros novos.
9. **CA8** (RNF2, RNF2b). Nenhum secret/token nem dado do corpo da resposta (ex.: `email`, `telefone`
   fictícios do teste) aparece em mensagem de exceção ou log.

## Riscos

- **CNPJ sem cadastro no Manager**: o formato da resposta veio na Q6, mas não o caso "não
  encontrado" (404, `api_status: 0` ou `data: []`). RF4 trata todos esses casos como erro: o job
  termina `completed_with_errors`, sem alterar o contrato, que fica com os defaults
  (`has_confrontation_module = true`, `has_open_finance_module = false`).
- **Dependência do formato do CNPJ**: o Manager só encontra o cliente com máscara (Q6). Se o Manager
  mudar isso, a consulta passa a voltar vazia → erro visível no `error_summary`, sem gravar nada.
- **Secret na query string** do `get-token` (P7): aparece em logs de acesso e em mensagens de erro
  do Guzzle. Mitigação no código (RNF2); o formato do endpoint é do Manager.
- **Ordem de deploy** (P4): a migration das colunas precisa estar no conciliador_web de produção
  antes do hyperf. Sem ela, todo job termina `completed_with_errors` (não quebra a migração).
- **`POST` para consulta**: pode ter efeito colateral no Manager (ex.: log/criação). Confirmar que é
  só leitura (Q1). Retomadas de job repetem a chamada.
- **Sobrescrever ajuste manual**: se um admin mudar o toggle no conciliadorweb e o job for retomado,
  o Manager volta a mandar. Aceitável — o passo só roda no fim da migração.

## Questões em aberto

1. **Contrato do endpoint.** É mesmo `POST manager.conciliadorcontabil.com.br/api/clientes?cnpj=`?
   Pode mandar um exemplo de resposta (com dados fictícios) e o que vem quando o CNPJ não existe?
   O endpoint atual `managerv2api…/api/public/clientes/cnpj` já devolve `belvo`/`confronto`? (Se sim,
   usamos a mesma chamada — alternativa de D1.)
   > **Resposta (2026-11-17):** sim, o endpoint é o mesmo (`POST /api/clientes?cnpj=` em
   > `manager.conciliadorcontabil.com.br`). Alternativa de D1 descartada. Exemplo de resposta não
   > enviado → vira a Q6.
2. **Autenticação.** O endpoint exige token? É o `MANAGER_SECRET` que o conciliadorweb já tem em
   `config/manager.php`? Em qual header?
   > **Resposta (2026-11-17):** seguir o `LayoutAdminSeeder` anterior ao commit `ee0cb1c9` — `get-token`
   > com o secret, depois Bearer (P7, D1).
3. **Valores negativos.** `belvo != 1` deve gravar `has_open_finance_module = false` e
   `confronto != "Sim"` deve gravar `has_confrontation_module = false`? (Recomendado: sim. Como
   `has_confrontation_module` nasce `true`, gravar só o positivo deixaria Confronto sempre `true` — P5.)
   > **Resposta (2026-11-17):** sim, valor diferente de `1` / `"Sim"` grava `false` (RF2–RF4).
   > **Obs. (2026-11-17):** se o Manager não retornar, `has_confrontation_module` fica `true` (o
   > default). Interpretado como: `confronto` ausente/`null` → `true`; falha ou cliente não
   > encontrado → nada é gravado e vale o default `true` da coluna (RF3, RF4, CA4).
4. **Quando rodar.** Sincronizar módulos mesmo quando outras entidades (não `contracts`) terminaram
   com erro? (Recomendado: sim — P6.) O `company_count` mantém a regra atual de só rodar sem erro?
   > **Resposta (2026-11-17):** basta a entidade `contracts` ter sucesso (RF1, D2). O `company_count`
   > não muda (RF8).
5. **Contratos já migrados.** Precisa de backfill (comando/endpoint no hyperf para rodar o sync num
   job concluído)? Se sim, entra nesta spec ou fica para depois?
   > **Resposta (2026-11-17):** não precisa de backfill (Não-objetivos).
6. **Formato da resposta de `/api/clientes`** (aberta em 2026-11-17). Os campos `belvo` e `confronto`
   vêm na raiz do JSON ou dentro de `data` (como em `/api/layouts`, P7)? `data` é um objeto ou uma
   lista? E quando o CNPJ não existe no Manager: 404, ou 200 com `data` vazio? Sem a resposta, a
   implementação aceita os dois formatos (raiz ou `data` objeto; `data` lista com um item) e trata
   lista vazia / `null` como erro.
   > **Resposta (2026-11-17):** exemplo real enviado (aqui com dados fictícios). O CNPJ **tem que ir
   > formatado** — só dígitos não retorna o cliente. Formato:
   > ```json
   > {
   >   "api_status": 1,
   >   "api_message": "success",
   >   "data": [
   >     { "id": 1, "tipo": "MATRIZ", "cnpj": "11.222.333/0001-81", "razaosocial": "…",
   >       "email": "…", "telefone": "…", "ativo": "Sim", "empresas": 2, "confronto": "Sim",
   >       "empresas_contr": 62, "banco": "cont_exemplo", "belvo": 1, … }
   >   ]
   > }
   > ```
   > `data` é **lista**; `belvo` é inteiro e `confronto` é `"Sim"`/`"Não"` dentro de cada item. O corpo
   > traz dados pessoais (RNF2b). O caso "CNPJ não encontrado" não foi informado → tratado como erro
   > (RF4, Riscos). Efeito: RF1, RF4, RNF2b, D1, CA1, CA4, CA6b, CA8.

## Arquivos envolvidos

Hyperf (`/var/www/api-hyperf-to-migration`):
- `app/Service/ContractModulesSyncService.php` (novo)
- `app/Service/MigrationOrchestrator.php` (`:34-38` injeção; `:143-152` passos pós-migração)
- `app/Service/ContractCompanyCountSyncService.php` (molde; muda só se Q1 levar à alternativa de D1)
- `test/Cases/Unit/Service/ContractModulesSyncServiceTest.php` (novo)
- `test/Cases/Unit/Service/MigrationOrchestratorTest.php` (novos casos)
- `.env.example`, `docs/ai/workflows.md`

Conciliadorweb (só referência / dependência de deploy):
- `database/migrations/2026_11_17_130000_add_unlimited_and_module_flags_to_contracts_table.php`
- `config/manager.php`
- `database/seeders/LayoutAdminSeeder.php` @ `ee0cb1c9^` (precedente de autenticação no Manager)
- `app/Filament/Admin/Resources/DatabaseMigrationResource/Pages/ViewDatabaseMigration.php`
