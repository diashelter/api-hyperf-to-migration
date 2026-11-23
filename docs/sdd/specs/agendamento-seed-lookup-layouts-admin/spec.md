# Spec: Atualizar o `lookup_cache` de `layouts_admin` a cada minuto (`api-hyperf-to-migration`)

> Status: proposto — questões 1–3 respondidas em 2026-11-23
> Slug: `agendamento-seed-lookup-layouts-admin`
> Data da investigação: 2026-11-23
> Repositório: **`api-hyperf-to-migration`** (Hyperf 3.1 / Swoole). Os caminhos `app/...`/`config/...`
> são deste repositório; os prefixados com `conciliadorweb:` são de `/var/www/conciliadorweb`.
> Correspondência: `conciliadorweb:routes/console.php:12` —
> `Schedule::job(new SyncAdminLayoutsJob)->everyMinute()->onOneServer();`

## Contexto

Os layouts admin (importação e exportação) chegam ao `conciliador_web.layouts_admin` pelo
`SyncAdminLayoutsJob` do conciliadorweb, que roda **a cada minuto, em um servidor só, sem
sobreposição** (`conciliadorweb:routes/console.php:12`, `conciliadorweb:app/Jobs/SyncAdminLayoutsJob.php:25`
— `ShouldBeUnique`, `uniqueFor = 300`, `tries = 1`, "o ciclo seguinte faz o papel do retry").

O migrator não consulta `layouts_admin` em tempo de migração: ele resolve o UUID pelo código numa
cópia local, a tabela `lookup_cache` (entity `layouts_admin`, `label = code`, `external_id = id`),
populada pelo comando `php bin/hyperf.php migration:seed-lookups layouts_admin`
(`app/Command/SeedLookupCacheCommand.php:24`, `app/Service/LookupCacheService.php:39`).

Pedido do usuário: rodar `migration:seed-lookups layouts_admin` **a cada 1 minuto**, no mesmo
regime do `SyncAdminLayoutsJob` (a cada minuto, em um servidor só).

Escopo: só a entidade `layouts_admin`. `roles`, `status` e `permissions` continuam manuais.
O comando e o `LookupCacheService` não mudam de comportamento.

## Investigação

Leitura do código do migrator (branch atual, commit `73f37b1`) e do conciliadorweb
(`releases-47-11-g`). **Não houve medição em execução**: o Docker Desktop estava desligado
(`docker ps` → `failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine`),
então nem o container `conciliador-migrator` nem os bancos estavam acessíveis. Tempo do seed e
volume de `layouts_admin` ficam para o baseline (T1 das tasks). Nenhum código foi alterado e nenhum
dado foi gravado.

### Evidências

| O quê | Onde | Achado |
|---|---|---|
| Quem chama `LookupCacheService::seed()` | `grep -rn "seed(\|seedAll" app` | Só `SeedLookupCacheCommand.php:58` e `:64`. Nenhum job, listener ou processo chama o seed. |
| Agendador no migrator | `composer.json:14-38`, `vendor/hyperf/`, `config/autoload/` | Sem `hyperf/crontab`, sem `config/autoload/crontab.php`. `processes.php:15` só registra `ConsumerProcess`. |
| Consumidores de `lookup_cache.layouts_admin` | `app/PullMode/Source/LayoutSource.php:55`, `app/PullMode/Source/CompanyLayoutSource.php:100` | `LayoutSource` preenche `layouts.layouts_admin_id` (códigos `< 1000` ou `> 5000`); `CompanyLayoutSource` preenche `company_layout.layout_exp`. `resolve()` devolve `null` quando o código não está no cache (`LookupCacheService.php:114-124`). |
| Sync de layouts EXP dentro da migração | `app/Service/MigrationOrchestrator.php:78-83` | Comentado: "Desabilitado migração dos layouts de exportação por enquanto, deve ser validados manualmente antes da migração". Os layouts EXP só existem no destino via conciliadorweb. |
| Unicidade de `code` | `conciliadorweb:database/migrations/2024_11_27_142702_create_layout_admins_table.php:15` | `code` é `unique()` — não há ambiguidade IMP × EXP no `resolve()` por `label`. |
| Idempotência do seed | `LookupCacheService.php:76-90` | Apaga do cache os ids que sumiram da origem e faz `upsert` em lotes de 500 por `(entity, external_id, environment)`. Origem vazia → retorna 0 sem apagar nada (`:59-61`). Sem transação, mas um leitor concorrente nunca perde uma linha válida (só somem ids que já não existem). |
| Ambiente da chave do cache | `LookupCacheService.php:163-166`, `Dockerfile` (`ENV APP_ENV=prod`), `docker-compose.prod.yml` (`migrator-worker`: `APP_ENV=dev`), `vendor/hyperf/support/src/DotenvManager.php` (`immutable()`) | Linhas são gravadas e lidas por `environment = APP_ENV`. O `.env` não sobrescreve a variável do container. Em `docker-compose.prod.yml`, `migrator` roda com `prod` e `migrator-worker` com `dev`. |
| Topologia | `docker-compose.yml`, `docker-compose.prod.yml` | `migrator` e `migrator-worker` usam a mesma imagem e o mesmo `ENTRYPOINT ... start`; o worker pode escalar (`--scale migrator-worker=N`). Ambos forkam os processos de `config/autoload/processes.php`. Redis compartilhado (`conciliador-migrator-redis`). |

### Causa raiz

**C1. O `lookup_cache` de `layouts_admin` só é atualizado à mão.** O único caminho para
`LookupCacheService::seed()` é o comando (`SeedLookupCacheCommand.php:58`) e o migrator não tem
agendador (`composer.json` sem `hyperf/crontab`; `processes.php:15` só com o consumer). Um layout
admin criado ou sincronizado no conciliadorweb depois do último seed manual não está no cache, e
`LayoutSource.php:55` / `CompanyLayoutSource.php:100` gravam `layouts_admin_id` / `layout_exp` como
`NULL` sem erro.

### Agravantes

**A1. `APP_ENV` diferente entre os containers de produção — fora do escopo (2026-11-23).** O
usuário confirmou que os valores vistos (`dev` e `local`) são o mesmo ambiente e decidiu que o
agendamento ignora o `APP_ENV` (questão 2; D4 descartado). Registro original: como o cache é particionado por
`environment` e cada container lê com o próprio `APP_ENV`, um job consumido pelo `migrator-worker`
(`dev`) lê linhas diferentes das que um job consumido pelo `migrator` (`prod`) lê. Um agendamento
"em um servidor só" atualizaria só uma das duas partições. Isso já acontece hoje com o seed manual
(roda no container em que foi executado); o agendamento deixa explícito.

**A2. Várias instâncias da mesma imagem.** Sem trava distribuída, cada container (e cada réplica do
worker) rodaria o seed a cada minuto — N execuções idênticas por minuto contra o `conciliador_web`.

## Objetivo

Um layout admin que aparece (ou some) no `conciliador_web.layouts_admin` passa a ser resolvido pelo
migrator em até ~1 minuto, sem ninguém rodar o comando, com no máximo uma execução por minuto no
conjunto de containers e sem sobreposição.

## Requisitos

### Funcionais

- **RF1.** A cada minuto, o migrator executa o equivalente a
  `php bin/hyperf.php migration:seed-lookups layouts_admin` (mesmo `LookupCacheService::seed('layouts_admin')`).
- **RF2.** Em cada minuto, só uma instância executa (correspondência com `onOneServer`), mesmo com
  `migrator` + N `migrator-worker`.
- **RF3.** Uma execução não começa enquanto a anterior não terminou (correspondência com
  `ShouldBeUnique`/`withoutOverlapping`).
- **RF4.** Falha numa execução (banco indisponível, timeout) é logada e não derruba o processo nem o
  servidor HTTP; o minuto seguinte faz o papel do retry (mesma política do `SyncAdminLayoutsJob`, `tries = 1`).
- **RF5.** O agendamento pode ser desligado por variável de ambiente sem novo deploy de código
  (dev, teste, ou incidente).
- **RF6.** O comando manual `migration:seed-lookups [entidade]` continua funcionando igual.

### Não-funcionais (metas)

- **RNF1.** Atraso entre um layout novo em `conciliador_web.layouts_admin` e sua presença no
  `lookup_cache` do ambiente: **≤ 2 min** (hoje: indefinido, depende de execução manual).
- **RNF2.** Execuções do seed por minuto, somando todos os containers: **= 1** com o agendamento
  ligado (sem trava seria `1 + N`).
- **RNF3.** Duração de uma execução do seed de `layouts_admin` **< 10 s** (a medir no T1; se passar
  disso, revisar o intervalo).
- **RNF4.** Log em nível `info` **só quando houver mudança** ou em erro — nada de linha por minuto
  sem mudança (mesma política do `SyncAdminLayoutsJob::logChanges`).

## Design (direção)

### D1. Adotar `hyperf/crontab` com processo dispatcher (resolve C1; RF1, RF5)

Adicionar `hyperf/crontab ~3.1.0` (mesma linha dos demais pacotes `hyperf/*` em `composer.json`).
Configurar em `config/autoload/crontab.php`, com `enable` vindo de env (ex.:
`CRONTAB_ENABLE`), padrão `true` em todos os ambientes, dev inclusive (questão 3); desligar é
opção explícita. Registrar o processo
dispatcher conforme a documentação da versão instalada (em 3.x a ativação é por `crontab.enable`;
conferir se ainda é preciso listar `CrontabDispatcherProcess` em `processes.php`). Regra
`* * * * *`, nome estável (ex.: `seed-lookup-layouts-admin`).

Alternativa considerada: processo próprio (`AbstractProcess`) com `sleep(60)` em loop — descartada
por reimplementar trava, regra cron e tratamento de erro que o pacote já tem.

### D2. Uma instância por minuto, sem sobreposição (resolve A2; RF2, RF3, RNF2)

Usar `onOneServer` + `singleton` do crontab, com `mutexPool` no Redis `default` (o mesmo do
async-queue, compartilhado por todos os containers). Uma tarefa só, com nome fixo que **não**
depende do `APP_ENV` (questão 2): a trava é global ao conjunto de containers. Ajustar `mutexExpires` para a cadência de
1 minuto (ordem de 60–120 s): o mutex de servidor do Hyperf é "pegajoso" — o container que pega a
trava mantém a tarefa até ela expirar —, e o padrão longo faria os outros containers esperarem
demais se o dono cair. Confirmar o comportamento exato de `RedisServerMutex`/`RedisTaskMutex` no
código do pacote instalado ao implementar.

### D3. Tarefa fina que reaproveita o serviço do comando (RF1, RF4, RNF4)

**Decidido (2026-11-23, questão 1): forma (b), classe própria.** Opções avaliadas:

- **(a) tipo `command`**: callback `['command' => 'migration:seed-lookups', 'entity' => 'layouts_admin']`
  (mais `--disable-event-dispatcher` se a doc da versão exigir). Literal ao pedido, mas imprime no
  stdout a cada minuto e não sabe se houve mudança.
- **(b) tipo `callback`**: classe `App\Crontab\SeedLayoutsAdminLookupTask` que chama
  `LookupCacheService::seed('layouts_admin')`, captura `Throwable` e loga erro, e loga `info` só se
  houve mudança. Para saber se houve mudança, `seed()` precisaria devolver contagens de
  inseridos/removidos (hoje devolve só o total upsertado) — mudança aditiva no serviço; o comando
  manual segue imprimindo o total.

Escolhida: **(b)**, pela política de log do RNF4 e por ser testável com mock do serviço.

### D4. Coerência do `APP_ENV` entre containers: descartado

**Descartado em 2026-11-23** (questão 2): o agendamento ignora o `APP_ENV`; nem o compose nem o
nome da tarefa mudam por causa dele. Opções que estavam em avaliação:

- **(a)** Alinhar `APP_ENV` dos serviços em `docker-compose.prod.yml` (worker com o mesmo valor do
  `migrator`) — uma partição só no cache, um agendamento só.
- **(b)** Incluir o `APP_ENV` no nome da tarefa (`seed-lookup-layouts-admin-{env}`), para a trava
  ser por ambiente e cada partição ter seu próprio seed por minuto.

Nenhuma das duas será feita.

### D5. Documentação

Atualizar `docs/ai/architecture.md` (seção do `lookup_cache`, ~linhas 431–451) e
`docs/ai/workflows.md:29-31`: `layouts_admin` é atualizado a cada minuto; como desligar; comando
manual continua valendo para as demais entidades.

## Não-objetivos

- Agendar `roles`, `status` ou `permissions`.
- Reativar o `ExportLayoutSyncService` na migração (`MigrationOrchestrator.php:78-83`).
- Trocar o `lookup_cache` por consulta direta ao `conciliador_web` em tempo de migração.
- Disparar o seed a partir do conciliadorweb (evento/webhook ao fim do `SyncAdminLayoutsJob`).
- Mudar o `SyncAdminLayoutsJob`.
- Alinhar ou tratar o `APP_ENV` entre containers (A1, D4 descartado). O `seed()` segue gravando com
  o `APP_ENV` do container que executa, como hoje.

## Critérios de aceite

1. **CA1** (RF1, RNF1). Com o agendamento ligado, inserir um `layouts_admin` com código novo no
   `conciliador_web` de dev; em até 2 min a linha `(layouts_admin, <id>, <code>, <APP_ENV>)` aparece
   em `lookup_cache`, e `LookupCacheService::resolve('layouts_admin', <code>)` devolve o id. Removido
   o layout, a linha some do cache no ciclo seguinte.
2. **CA2** (RF2, RNF2). Com `migrator` + `migrator-worker` no ar, em 5 minutos o seed roda 5 vezes no
   total (contado pelos logs ou por `updated_at` distintos), não 10.
3. **CA3** (RF3). Teste unitário comprova a configuração da tarefa: regra `* * * * *`, nome estável,
   `onOneServer = true`, `singleton = true`, `mutexExpires` dentro da faixa decidida.
4. **CA4** (RF4). Teste unitário: exceção do `LookupCacheService` é capturada e logada em `error`; a
   tarefa não propaga.
5. **CA5** (RNF4). Teste unitário: sem mudança, nenhum log `info`; com mudança, um log `info` com as
   contagens.
6. **CA6** (RF5). Sem a env definida, o agendamento está ligado (padrão `true`); com a env de
   desligar, o processo dispatcher não sobe (ou a tarefa não está registrada) — verificado por teste
   de config ou pelo log de boot.
7. **CA7** (RF6). `php bin/hyperf.php migration:seed-lookups` e `... layouts_admin` imprimem e gravam
   como antes.
8. **CA8** (RNF3). Duração do seed registrada em `validation.md` (antes/depois) e abaixo de 10 s.

## Riscos

- **Mutex de servidor pegajoso** (D2): com `mutexExpires` longo, se o container dono da trava cair o
  seed para até a expiração. Mitigado pela faixa de 60–120 s.
- **Processo extra em cada container**: o dispatcher do crontab sobe em `migrator` e em todo
  `migrator-worker`. Custo baixo (um processo ocioso), mas muda a contagem de processos do Swoole.
- **Concorrência com migração em curso**: o seed roda enquanto um job lê o cache. O `seed()` só
  remove ids que sumiram da origem e faz `upsert` dos demais; um leitor não vê uma linha válida
  desaparecer. Um layout removido do conciliadorweb no meio de uma migração passa a resolver `NULL`
  — mesmo efeito do seed manual.
- **`deploy.test.yml`** usa outro serviço (`hyperf`, `.env` via `configs`): precisa da variável de
  ligar/desligar no config externo `hyperf_v1.0`.
- **Pacote novo** (`hyperf/crontab`): exige rebuild da imagem e `composer install` no container de dev.

## Questões em aberto

1. **D3 — forma da tarefa:** (a) tipo `command`, literal ao pedido, ou (b) tipo `callback` com
   classe própria, log só quando muda e `seed()` devolvendo contagens? (Recomendado: b.)

   **Resposta (2026-11-23):** classe própria — D3 (b). `App\Crontab\SeedLayoutsAdminLookupTask` +
   contagens de mudança no `LookupCacheService::seed()`.

2. **D4 — `APP_ENV` divergente em `docker-compose.prod.yml`** (`migrator` = `prod`, `migrator-worker` = `dev`):
   (a) alinhar o compose, ou (b) uma tarefa por ambiente? O valor de produção correto é `prod`? (Recomendado: a.)

   **Resposta (2026-11-23):** `dev` e `local` são o mesmo ambiente; ignorar o `APP_ENV` no
   agendamento. D4 descartado, A1 fora do escopo; tarefa única com nome fixo (D2).

3. **Ligado por padrão em dev?** A env de controle começa `true` em todos os ambientes ou só em
   prod/teste (dev precisa ligar à mão)?

   **Resposta (2026-11-23):** ligado por padrão em todos os ambientes, dev inclusive.

## Arquivos envolvidos

- `composer.json`, `composer.lock` — `hyperf/crontab ~3.1.0`
- `config/autoload/crontab.php` (novo)
- `config/autoload/processes.php` — dispatcher, se a versão exigir
- `app/Crontab/SeedLayoutsAdminLookupTask.php` (novo)
- `app/Service/LookupCacheService.php:39-93` — contagens de mudança (inseridos/atualizados/removidos)
- `app/Command/SeedLookupCacheCommand.php` — sem mudança de comportamento (RF6)
- `docker-compose.prod.yml`, `docker-compose.yml`, `.env.example` — só a env de ligar/desligar (D1); `APP_ENV` não muda
- `docs/ai/architecture.md`, `docs/ai/workflows.md` — D5
- `test/Cases/Unit/...` — testes novos (CA3–CA6)
- Referência: `conciliadorweb:routes/console.php:12`, `conciliadorweb:app/Jobs/SyncAdminLayoutsJob.php`
