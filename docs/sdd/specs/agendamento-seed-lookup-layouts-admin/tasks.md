# Tasks: Atualizar o `lookup_cache` de `layouts_admin` a cada minuto

> Referência: [spec.md](./spec.md)
> Slug: `agendamento-seed-lookup-layouts-admin`
> Repositório: `api-hyperf-to-migration` (todos os caminhos são deste repositório)

Ordem sugerida: T1 (baseline) → T2 (pacote) → T3 (contagens no serviço) → T4 (tarefa) →
T5 (agendamento) → T6 (docs/env) → T7 (validação). T2 e T3 não dependem uma da outra; T4 depende
de T3; T5 depende de T2 e T4. O ganho funcional aparece em T5.

Decisões já tomadas (respostas da spec, 2026-11-23):
- Q1 / D3 (b): tarefa em classe própria `App\Crontab\SeedLayoutsAdminLookupTask`, tipo `callback`
  do crontab, chamando o `LookupCacheService`. O `LookupCacheService` ganha contagens de mudança
  (inseridos/atualizados/removidos) para o log só-quando-muda (RNF4). Isso é **aditivo**: a frase
  do Contexto da spec "o `LookupCacheService` não muda de comportamento" vale para `seed()`, que
  continua devolvendo o mesmo `int` e gravando igual (RF6).
- Q2 / D4 descartado: o agendamento ignora o `APP_ENV`. Uma tarefa só, nome fixo
  `seed-lookup-layouts-admin`, sem sufixo de ambiente; `docker-compose*.yml` não muda o `APP_ENV`.
  O `seed()` segue gravando com o `APP_ENV` do container que executa.
- Q3: agendamento **ligado por padrão em todos os ambientes**, dev inclusive. A env de controle
  (`CRONTAB_ENABLE`) só serve para desligar (`false`).
- RNF4 × log do `Executor` do crontab (2026-11-23, fonte: orquestrador, pelo texto da RNF4 —
  "nada de linha por minuto sem mudança", sem restringir ao logger da tarefa): **opção B**. Na T5,
  bind de `Hyperf\Crontab\LoggerInterface` em `config/autoload/dependencies.php` para um logger
  pequeno em `app/Crontab/` que repassa só `warning` ou acima, com teste unitário (info descartado,
  warning/error repassados). Problemas de ambiente do composer achados no T2 ficam fora do escopo.
- RNF4 × log SQL do `DbQueryExecutedListener` (2026-11-23, fonte: usuário, via orquestrador):
  **opção B** — silenciar o log SQL só durante a tarefa. A `SeedLayoutsAdminLookupTask` liga uma
  flag no `Hyperf\Context\Context` (constante pública `DbQueryExecutedListener::SKIP_LOG_CONTEXT_KEY`)
  antes do seed e a remove num `finally`; o listener não loga com a flag ligada. O log SQL das
  migrações, do comando manual e do resto da aplicação não muda. Entra na T5, com testes da tarefa
  (flag ligada durante o seed e desligada depois, inclusive com exceção) e do listener.

Testes: rodar só os arquivos alterados/criados, pela trava do orquestrador (aplica o container e o
contorno do `pgsql` quebrado, ver `exec_setup` em `~/.claude/skills/orquestrar-specs/repos.json`):

```bash
ORCH_ROOT=/var/www/api-hyperf-to-migration ~/.claude/skills/orquestrar-specs/scripts/container.sh \
  vendor/bin/phpunit -c phpunit.unit.xml.dist <ARQUIVOS>
```

Análise estática: mesmo prefixo com `composer analyse`. Comandos Hyperf: mesmo prefixo com
`php bin/hyperf.php ...`. Pré-requisito de ambiente: Docker Desktop ligado e containers
`conciliador-migrator`, `conciliador-migrator-worker`, `conciliador-migrator-postgres` e
`conciliador-migrator-redis` no ar (`docker compose up -d` na raiz do repo). Na investigação o
Docker estava desligado; nada foi medido.

Padrão de teste unitário com banco: não há banco nos unit tests (`phpunit.unit.xml.dist` só carrega
`vendor/autoload.php`). O `Db` é simulado com `Mockery::mock('alias:Hyperf\DbConnection\Db')` e a
classe de teste usa `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]` — molde em
`test/Cases/Unit/Service/ContractModulesSyncServiceTest.php:34-36` e `:436-486`. Convenções em
`docs/ai/testing.md` (classe `final`, `extends HyperfTest\UnitTestCase`, `test{OQueTesta}`,
`setEnvValue()` para env).

Testes existentes relacionados: **nenhum** cobre `LookupCacheService`, `SeedLookupCacheCommand` ou
crontab (`grep -rl "LookupCache\|SeedLookup\|Crontab" test` → vazio).

## T1. Baseline: caracterização do `seed()` e medição do seed atual

**Status:** concluída (2026-11-23)
**Notas de execução:** criado `test/Cases/Unit/Service/LookupCacheServiceTest.php` com os 6 casos
de caracterização (alias mock de `Db`, processo separado); o `selectRaw`, os ids do `whereNotIn` e os
lotes do `upsert` são capturados por `andReturnUsing` e conferidos com `assertSame`/`assertCount`
(`shouldHaveReceived` não conta asserção e deixava o teste "risky"). Código de produção não mudou.
Medição "antes" em `validation.md`: 1222 linhas em `layouts_admin`, comando com mediana de 0,792 s
(inclui boot). O `php bin/hyperf.php` rodou pela trava sem o contorno do pgsql.
**Objetivo:** fixar o comportamento atual de `LookupCacheService::seed()` antes de mexer nele, e
medir o seed de `layouts_admin` em dev.
- Criar `test/Cases/Unit/Service/LookupCacheServiceTest.php` (`#[CoversClass(LookupCacheService::class)]`,
  alias mock de `Db` como no molde). Casos de caracterização (todos passam no código atual):
  - `testSeedThrowsForUnsupportedEntity` — `seed('foo')` lança `InvalidArgumentException`.
  - `testSeedReturnsZeroAndDoesNotDeleteWhenSourceIsEmpty` — origem vazia → `0`, sem `delete()` nem
    `upsert()` em `lookup_cache` (`app/Service/LookupCacheService.php:59-61`).
  - `testSeedLayoutsAdminSelectsIdAndCodeFromConciliadorWeb` — `Db::connection('conciliador_web')->table('layouts_admin')->selectRaw('id as external_id, code as label')`.
  - `testSeedDeletesCachedIdsMissingFromSourceForEntityAndEnvironment` — `Db::table('lookup_cache')->where('entity','layouts_admin')->where('environment', <APP_ENV>)->whereNotIn('external_id', [...])->delete()`
    (`:77-81`); usar `setEnvValue('APP_ENV', 'testing')`.
  - `testSeedUpsertsInChunksOf500WithUniqueKeyAndUpdateColumns` — 501 linhas → 2 chamadas de
    `upsert($chunk, ['entity','external_id','environment'], ['label','payload','updated_at'])`
    (`:83-90`); `external_id` convertido para string.
  - `testSeedReturnsNumberOfSourceRows`.
- Medir em dev (precisa do Docker): número de linhas de `conciliador_web.layouts_admin` e tempo de
  `time php bin/hyperf.php migration:seed-lookups layouts_admin` (3 execuções, mediana), pelo
  prefixo de container acima. Registrar em `validation.md` como "antes" (RNF3, CA8).
**Critério de pronto:** `LookupCacheServiceTest` verde com o comando de testes; medições "antes"
registradas em `validation.md` (criar com o template da skill). Se o Docker não estiver disponível,
marcar a medição como pendente em `validation.md` e seguir — ela é repetida no T7.
**Dependências:** nenhuma.
**Cobre Critério de Aceite:** CA7 (parte unitária), CA8 (antes).

## T2. Instalar `hyperf/crontab` e criar a configuração ligada por padrão (D1)

**Status:** concluída (2026-11-23)
**Notas de execução:**
- Instalado `hyperf/crontab` **v3.1.67** (`composer.json` + `composer.lock`: o lock só ganhou esse
  pacote; as dependências dele já estavam no lock). `composer require` puro falhou: o lock do
  repositório tem `malkusch/lock 2.3.0` (`php >=7.4 <8.4`, via `hyperf/rate-limit` →
  `hyperf/token-bucket`) e o container roda PHP 8.4.14. Foi usado
  `composer require hyperf/crontab:~3.1.0 --ignore-platform-req=php --no-install`.
- **Dev fora de sincronia com o lock (problema anterior a esta spec):** o `vendor/` de dev está à
  frente do `composer.lock` (dry-run do `composer install` = 28 downgrades, 1 upgrade, 1 remoção).
  Para não mexer no resto do ambiente, o pacote foi instalado só ele: lock temporário
  (`composer-tmp.json/.lock`, montado a partir de `vendor/composer/installed.json` + a entrada do
  crontab do lock novo) e `COMPOSER=composer-tmp.json composer install --ignore-platform-req=php`
  → "1 install, 0 updates, 0 removals". Os arquivos temporários foram apagados. Consequência fora do
  escopo: o `composer install --no-dev` do `Dockerfile` (PHP 8.4) falha na checagem de plataforma por
  causa do `malkusch/lock 2.3.0` do lock, com ou sem esta spec.
- Achado 1 (ativação): o `ConfigProvider` do pacote registra o comando `crontab:run`, os listeners
  `CrontabRegisterListener`/`OnPipeMessageListener` e os binds `StrategyInterface → WorkerStrategy`,
  `ServerMutex → RedisServerMutex`, `TaskMutex → RedisTaskMutex`, mas **não** o processo. É preciso
  listar `CrontabDispatcherProcess::class` em `config/autoload/processes.php` (feito);
  `CrontabDispatcherProcess::isEnable()` lê `crontab.enable` (padrão `false`) e o listener também só
  registra as tarefas com `crontab.enable` verdadeiro. `WorkerStrategy` exige `Swoole\Server` (o
  `server.php` usa `Hyperf\Server\Server`, `SWOOLE_BASE`, com `ON_PIPE_MESSAGE`): a tarefa roda num
  worker HTTP, escolhido em rodízio.
- Achado 2 (API): `Hyperf\Crontab\Crontab` tem `setName`, `setRule`, `setType` (padrão `callback`),
  `setCallback`, `setSingleton`, `setOnOneServer`, `setMutexPool` (padrão `default`),
  `setMutexExpires` (padrão **60**), `setMemo`, `setEnable`, `setTimezone`, `setEnvironments`
  (vazio = todos os ambientes), `setOptions`. `crontab.crontab` aceita objetos `Crontab`
  (`CrontabRegisterListener::parseCrontabs`, indexados por nome).
- Achado 3 (mutex): `RedisServerMutex` faz `SET hyperf/crontab-sha1(nome+regra)-sv <nó> NX EX
  mutexExpires`, onde o nó é `interface:mac` de `swoole_get_local_mac()` (um por container; sem MAC
  válido a tarefa nunca roda). Quem ganha cria um timer de 1 s que soma 1 ao TTL (`expire(ttl+1)`): a
  trava **não expira enquanto o dono vive** e é apagada no `WORKER_EXIT`; os demais containers pulam
  com log `info` "skipped execution ... caused by server mutex". Se o dono morre sem desligar, a
  trava some em ~`mutexExpires` s. `RedisTaskMutex` (singleton): `SET framework/crontab-sha1(nome+regra)
  <nome> NX EX mutexExpires`, renovado a cada 1 s enquanto a tarefa roda e apagado no fim. Logo
  `mutexExpires` é a janela de failover após queda abrupta, não a duração da posse — a faixa 60–120 s
  da D2 continua valendo (90 s planejado no T5).
- Achado 4 (callback): `Executor` faz `[$class, $method] = callback`, confere `class_exists`/
  `method_exists` e executa `make($class)->{$method}(...$params)` (container de DI, com o 3º item
  opcional como parâmetros). Exceções são capturadas pelo `Executor` (evento `FailToExecute` + log
  `error`); o processo não cai.
- **Divergência de log (RNF4), para decidir antes do T5:** o `Executor` loga em `info`, a cada
  execução, "Crontab task [nome] executed successfully at ..." no container dono e "skipped ...
  caused by server mutex" nos demais, no `Hyperf\Crontab\LoggerInterface` ou, sem bind, no
  `StdoutLoggerInterface` — que aqui aceita `info` e `debug` (`config/config.php`). Ou seja, com o
  pacote padrão há linhas `info` por minuto mesmo sem mudança.
- `config/autoload/crontab.php` criado com `'enable' => (bool) env('CRONTAB_ENABLE', true)` e
  `'crontab' => []`. Verificação: `php bin/hyperf.php` lista `crontab:run` e os comandos `migration:*`
  sem erro; `docker restart conciliador-migrator conciliador-migrator-worker` → os dois sobem com
  `Process[crontab-dispatcher.0] start.` e sem exceção. `composer analyse`: 1 erro, anterior a esta
  spec (`config/autoload/async_queue.php:36`, `FailedHandler` não encontrado); nenhum erro novo.
**Objetivo:**
- `composer require hyperf/crontab:~3.1.0` pelo container (atualiza `composer.json:14-38` e
  `composer.lock`).
- **Conferir no código instalado** (`vendor/hyperf/crontab/src/`) e registrar nas notas de execução:
  1. como o dispatcher é ativado na 3.1 — só `crontab.enable` ou também
     `Hyperf\Crontab\Process\CrontabDispatcherProcess::class` em `config/autoload/processes.php:14-16`
     (ver `ConfigProvider.php` e o listener/processo do pacote);
  2. setters disponíveis em `Hyperf\Crontab\Crontab` (`setName`, `setRule`, `setType`, `setCallback`,
     `setSingleton`, `setOnOneServer`, `setMutexPool`, `setMutexExpires`, `setEnable`, …) e se
     `config/autoload/crontab.php` aceita objetos `Crontab` na chave `crontab`;
  3. comportamento de `Mutex\RedisServerMutex` (chave, `NX EX`, valor por servidor) e de
     `Mutex\RedisTaskMutex`, e o default de `mutexExpires`;
  4. como o tipo `callback` resolve `[Classe::class, 'metodo']` (via container?).
  Se algo divergir da spec (D1/D2), registrar aqui e avisar o usuário antes do T5 — não reescrever a spec.
- Criar `config/autoload/crontab.php` com `'enable' => env('CRONTAB_ENABLE', true)` (cast para
  bool; `"false"` desliga) e `'crontab' => []` por enquanto. Registrar o dispatcher em
  `processes.php` só se o item 1 exigir.
- Rebuild/`composer install` no container de dev (risco "Pacote novo" da spec).
**Critério de pronto:** `php bin/hyperf.php` lista os comandos sem erro; servidor sobe
(`docker compose restart migrator migrator-worker` e log de boot sem exceção, com o processo do
crontab presente); `composer analyse` sem erro novo; achados 1–4 nas notas de execução.
**Dependências:** nenhuma.
**Cobre Critério de Aceite:** base para CA3, CA6.

## T3. Contagens de mudança no `LookupCacheService` (D3 b)

**Status:** concluída (2026-11-23)
**Notas de execução:** criado `app/Service/LookupSeedResult.php` (sem `readonly class`, PHP ≥ 8.1).
Em `LookupCacheService`, o corpo de `seed()` foi para `seedWithChanges()`; `seed()` devolve
`->total`. A única query nova é a leitura `pluck('label', 'external_id')` do cache do ambiente,
feita antes do `delete` e só quando a origem não está vazia. `removed` vem do retorno do `delete()`
que já existia. A comparação de label normaliza para `?string` (`countInsertedAndUpdated` +
`normalizeLabel`), porque o `code` chega inteiro da origem e o cache guarda texto. Chaves numéricas
do `pluck` funcionam com `array_key_exists` (o PHP normaliza a chave). Testes: os 6 do T1 seguem iguais
(o mock ganhou `pluck`) e há 3 novos; um deles cobre código inteiro × texto e id numérico.
`php-cs-fixer` (dry-run) aplicado nos arquivos da spec: também ajustou `config/autoload/crontab.php`
(`'crontab' => []`), arquivo do T2.
**Objetivo:** permitir que a tarefa saiba se o seed mudou algo, sem mudar `seed()`.
- Novo `app/Service/LookupSeedResult.php`: `final class LookupSeedResult` com propriedades
  `public readonly` promovidas `string $entity`, `int $total`, `int $inserted`, `int $updated`,
  `int $removed`, e `hasChanges(): bool` (`inserted + updated + removed > 0`). PHP ≥ 8.1
  (`composer.json`), então sem `readonly class`.
- Em `app/Service/LookupCacheService.php`, extrair o corpo de `seed()` (`:39-93`) para
  `seedWithChanges(string $entity): LookupSeedResult`:
  - antes do `delete`, ler o estado atual do cache uma vez:
    `Db::table('lookup_cache')->where('entity', $entity)->where('environment', $env)->pluck('label', 'external_id')`;
  - `inserted` = ids da origem ausentes do cache; `updated` = ids presentes com `label` diferente;
    `removed` = retorno do `delete()` existente (`:77-81`);
  - origem vazia → `LookupSeedResult` com tudo `0` e **sem** ler/apagar nada (mantém `:59-61`).
  - `seed()` passa a ser `return $this->seedWithChanges($entity)->total;` — mesmas queries de escrita,
    mesmo retorno (RF6). `seedAll()` e o comando não mudam.
- Testes em `LookupCacheServiceTest.php`: os do T1 continuam verdes (ajustar só o mock para a nova
  leitura `pluck`), e novos:
  `testSeedWithChangesCountsInsertedUpdatedAndRemoved`,
  `testSeedWithChangesReportsNoChangesWhenCacheMatchesSource`,
  `testSeedWithChangesReturnsZerosAndSkipsCacheWhenSourceIsEmpty`.
**Critério de pronto:** `LookupCacheServiceTest` verde; `composer analyse` sem erro novo;
`php bin/hyperf.php migration:seed-lookups layouts_admin` imprime o mesmo texto de antes.
**Dependências:** T1.
**Cobre Critério de Aceite:** CA5 (pré-requisito), CA7.

## T4. Tarefa `SeedLayoutsAdminLookupTask` (D3 b)

**Status:** concluída (2026-11-23)
**Notas de execução:** **desvio do plano (decidido pelo orquestrador em 2026-11-23):** em vez de
injeção por construtor, as dependências usam `#[Inject]` (`protected LookupCacheService
$lookupCacheService`, `protected LoggerFactory $loggerFactory`), como manda `docs/ai/styleguide.md`
("No constructor injection"). O logger `lookup-cache` é obtido em `execute()`. O `Executor` do
crontab cria a classe com `make()`, então a injeção por propriedade funciona. A classe não é `final`,
como as demais classes do `app/` que usam `#[Inject]`. Log de erro com `exception` (classe) e
`message`, sem relançar. Teste sem banco e sem processo separado: as dependências entram via
`injectProperty()` e o logger mockado captura `[nível, mensagem, contexto]`. Arquivos novos:
`app/Crontab/SeedLayoutsAdminLookupTask.php` e `test/Cases/Unit/Crontab/SeedLayoutsAdminLookupTaskTest.php`.
**Objetivo:**
- Novo `app/Crontab/SeedLayoutsAdminLookupTask.php` (namespace `App\Crontab`, pasta nova; o
  autoload PSR-4 `App\\` já cobre). Construtor com `LookupCacheService` e `LoggerFactory`
  (logger `$loggerFactory->get('lookup-cache')`, padrão de `app/Service/MigrationOrchestrator.php:43-46`).
  Método público `execute(): void`:
  - chama `seedWithChanges('layouts_admin')`;
  - se `hasChanges()`: `logger->info('lookup_cache layouts_admin sincronizado', [total, inserted, updated, removed])`;
  - sem mudança: nenhum log `info` (RNF4);
  - captura `Throwable`: `logger->error(...)` com classe e mensagem da exceção, **não** relança (RF4) —
    o minuto seguinte é o retry.
  - Constante `ENTITY = 'layouts_admin'`. Nada de `APP_ENV` no código da tarefa (Q2).
- Criar `test/Cases/Unit/Crontab/SeedLayoutsAdminLookupTaskTest.php` com mocks de
  `LookupCacheService` e `LoggerInterface`/`LoggerFactory` (sem banco, sem processo separado):
  `testExecuteLogsInfoWithCountsWhenSeedHasChanges`,
  `testExecuteDoesNotLogInfoWhenSeedHasNoChanges`,
  `testExecuteLogsErrorAndDoesNotRethrowWhenSeedFails`,
  `testExecuteSeedsOnlyLayoutsAdmin`.
**Critério de pronto:** teste novo verde; `composer analyse` sem erro novo.
**Dependências:** T3.
**Cobre Critério de Aceite:** CA4, CA5.

## T5. Registrar o agendamento a cada minuto, um servidor, sem sobreposição (D1, D2)

**Status:** concluída (2026-11-23)
**Notas de execução:**
- `config/autoload/crontab.php`: um objeto `Crontab` (`seed-lookup-layouts-admin`, `* * * * *`,
  `callback` `[SeedLayoutsAdminLookupTask::class, 'execute']`, `singleton`, `onOneServer`,
  `mutexPool = default`, `mutexExpires = 90`, memo), comentário no topo com a correspondência
  `conciliadorweb:routes/console.php:12` e a semântica do `mutexExpires` (janela de failover, achado 3
  do T2). Sem `setEnvironments` (vazio = todos, Q2).
- Logger (decisão B): `app/Crontab/WarningLevelCrontabLogger.php` estende `Psr\Log\AbstractLogger`,
  implementa `Hyperf\Crontab\LoggerInterface` e repassa ao `StdoutLoggerInterface` (`#[Inject]`) só
  `warning` a `emergency`. Bind em `config/autoload/dependencies.php`. O `Executor`, o
  `CrontabDispatcherProcess`, o `WorkerStrategy` e o `CrontabRegisterListener` preferem esse bind
  (`container->has(LoggerInterface)`), então somem o "executed successfully"/"skipped ... server mutex"
  (info) e o "Crontab dispatcher sleep" (debug, a cada minuto).
- **Desvio no critério de pronto:** "tarefa registrada no log de boot" não aparece — o
  `CrontabRegisterListener` loga o registro em `debug` ("Crontab ... have been registered."), descartado
  pela decisão B. O registro foi comprovado pelo efeito: chave `hyperf/crontab-<sha1>-sv` no Redis db 5
  (dono `eth0:FAC3A1FA2CE9` = `conciliador-migrator`, TTL 90) e `updated_at` avançando a cada minuto;
  nenhum warning "invalid"/"disabled" no boot.
- **Achado (resolvido pela decisão B de 2026-11-23, abaixo):** `app/Listener/DbQueryExecutedListener.php` (skeleton do Hyperf,
  `initial commit`) loga **toda** query em `info` no canal `sql` → `runtime/logs/hyperf.log`
  (`StreamHandler`, nível DEBUG, sem rotação; o arquivo de dev já tem 1,7 GB). Cada execução do seed
  gera 6 linhas, ~200 KB (o `delete ... not in (...)` com 1222 UUIDs e os 3 `upsert`): ~290 MB/dia
  só desta tarefa, sem mudança nenhuma. Fere a letra da RNF4 ("nada de linha por minuto sem mudança").
- Decisão B aplicada: constante `DbQueryExecutedListener::SKIP_LOG_CONTEXT_KEY = 'db_query_log.skip'`;
  o `process()` retorna cedo com `Context::get(chave) === true`. Na tarefa, `Context::set(chave, true)`
  antes do `try` e `Context::destroy(chave)` no `finally` (cobre sucesso, sem mudança e exceção). O
  `Context` é por corrotina e o `seedWithChanges()` roda as queries na própria corrotina (sem `go`/
  `parallel`), então só as queries da tarefa ficam fora do log. Testes: 2 novos na tarefa e 2 novos
  no listener (o teste do listener já existia, com 3 casos). Verificado em dev depois do restart:
  0 linhas `sql` por minuto da tarefa (antes: 6 linhas, 201 918 bytes); o comando manual segue
  logando o SQL (6 linhas, ~200 KB).
- Testes novos: `test/Cases/Unit/Config/CrontabConfigTest.php` (3) e
  `test/Cases/Unit/Crontab/WarningLevelCrontabLoggerTest.php` (3). O `phpunit.unit.xml.dist` não define
  `BASE_PATH`; o teste de config acha o arquivo por `__DIR__`.
**Objetivo:** em `config/autoload/crontab.php` (criado no T2), adicionar uma tarefa, com a API
confirmada no T2:
- nome `seed-lookup-layouts-admin` (fixo, sem `APP_ENV`), regra `* * * * *`, tipo `callback`,
  callback `[App\Crontab\SeedLayoutsAdminLookupTask::class, 'execute']`;
- `singleton = true` (RF3), `onOneServer = true` (RF2), `mutexPool = 'default'` (Redis compartilhado,
  `config/autoload/redis.php`), `mutexExpires = 90` (faixa 60–120 s da D2; ajustar se o achado 3 do
  T2 indicar outra semântica, registrando o motivo);
- memo curto no topo do arquivo apontando a correspondência com
  `conciliadorweb:routes/console.php:12`.
- Logger do crontab (decisão RNF4 de 2026-11-23): nova classe em `app/Crontab/` (ex.:
  `WarningLevelCrontabLogger`, nome livre) que implementa `Hyperf\Crontab\LoggerInterface` e repassa
  ao `StdoutLoggerInterface` só `warning`, `error`, `critical`, `alert` e `emergency` (descarta
  `info`, `notice` e `debug`); bind em `config/autoload/dependencies.php`. Teste em
  `test/Cases/Unit/Crontab/` cobrindo `info` descartado e `warning`/`error` repassados.
- Log SQL (decisão de 2026-11-23): `app/Listener/DbQueryExecutedListener.php` ganha
  `SKIP_LOG_CONTEXT_KEY` e não loga com a flag ligada no `Context`; `SeedLayoutsAdminLookupTask`
  liga a flag antes do seed e a remove num `finally`. Testes em `SeedLayoutsAdminLookupTaskTest`
  (flag ligada durante o seed, desligada depois, inclusive com exceção) e em
  `test/Cases/Unit/Listener/DbQueryExecutedListenerTest.php` (sem flag loga, com flag não loga).
- Criar `test/Cases/Unit/Config/CrontabConfigTest.php`: carrega `config/autoload/crontab.php`
  (`require`) e verifica:
  `testCrontabIsEnabledByDefault` (`setEnvValue('CRONTAB_ENABLE', null)` → `enable === true`),
  `testCrontabCanBeDisabledByEnv` (`'false'` → `false`),
  `testSeedLayoutsAdminTaskRunsEveryMinuteOnOneServerWithoutOverlap` (nome, regra, tipo, callback,
  `singleton`, `onOneServer`, `mutexPool`, `mutexExpires` entre 60 e 120).
**Critério de pronto:** `CrontabConfigTest` verde; servidor reiniciado em dev mostra a tarefa
registrada no log de boot e o `lookup_cache` de `layouts_admin` com `updated_at` avançando a cada
minuto.
**Dependências:** T2, T4.
**Cobre Critério de Aceite:** CA3, CA6.

## T6. Documentação e variável de ambiente (D5, RF5)

**Status:** concluída (2026-11-23)
**Notas de execução:**
- `docs/ai/architecture.md`, seção "Lookup cache": nova subseção "Atualização automática de
  `layouts_admin`" (tarefa, trava `onOneServer`/`singleton` no Redis, `mutexExpires` 90 s como janela de
  failover, dispatcher em `processes.php`, `CRONTAB_ENABLE=false`). Também ficou registrado, a pedido do
  orquestrador (2026-11-23): o logger do crontab filtra abaixo de `warning`; a tarefa silencia o log SQL
  via `DbQueryExecutedListener::SKIP_LOG_CONTEXT_KEY`; o comando manual e as migrações continuam logando
  SQL. "Uso atual" corrigido: `LayoutSource` incluído; `CompanyLayoutSource` resolve por
  `lookup_cache` (sync de exportação desligado). Nota de que as linhas são por `APP_ENV`.
- `docs/ai/workflows.md`: só um comentário remetendo à seção de arquitetura, sem repetir a regra.
- `.env.example`: `CRONTAB_ENABLE=true` com comentário, antes de "Discord Notifications".
- `docker-compose*.yml` não mudaram (padrão `true`, `APP_ENV` intocado).
- Fora do escopo do T6 e não alterado: `docs/ai/architecture.md:325-330` ("Antes de migrar as entidades,
  `ExportLayoutSyncService` garante...") e `docs/ai/overview.md:28` ainda descrevem o sync de layouts de
  exportação como ativo, embora ele esteja comentado em `MigrationOrchestrator.php:78-83`.
**Objetivo:**
- `.env.example`: adicionar `CRONTAB_ENABLE=true` com comentário ("false desliga o agendamento do
  seed de layouts_admin").
- `docker-compose.yml` / `docker-compose.prod.yml`: **não** é preciso definir a variável (padrão
  `true`); não mexer no `APP_ENV` (Q2). Só documentar.
- `docs/ai/architecture.md:431-451` (seção "Lookup cache"): `layouts_admin` é atualizado a cada
  minuto pela tarefa `seed-lookup-layouts-admin` (um container por vez, sem sobreposição, desligável
  por `CRONTAB_ENABLE=false`); as demais entidades seguem manuais. Corrigir a linha que diz que
  `CompanyLayoutSource` usa "mappings de `layouts_admin`, alimentados por `ExportLayoutSyncService`":
  hoje ele resolve por `lookup_cache` (`app/PullMode/Source/CompanyLayoutSource.php:100`) e o sync
  está comentado (`app/Service/MigrationOrchestrator.php:78-83`). Incluir `LayoutSource`
  (`app/PullMode/Source/LayoutSource.php:55`) na lista de usos.
- `docs/ai/workflows.md:29-31`: nota de que `layouts_admin` já é atualizado automaticamente.
- Lembrete no relatório final (não é código): o deploy de teste (`deploy.test.yml`, config externo
  `hyperf_v1.0`) só precisa da variável se quiser desligar.
**Critério de pronto:** docs e `.env.example` atualizados; nenhuma regra duplicada fora de
`docs/ai/` (regra de ouro do `CLAUDE.md`).
**Dependências:** T5.
**Cobre Critério de Aceite:** RF5 (documentação).

## T7. Validação final por comandos e medição

**Status:** concluída (2026-11-23)
**Notas de execução:** CA1–CA8 verificados em dev; números em `validation.md`. Execução seguindo as
orientações do orquestrador (2026-11-23):
- CA2: a tarefa não loga sem mudança e o log SQL dela está silenciado, então a contagem foi feita por
  `pg_stat_user_tables.n_tup_upd` (+1222 por seed) além do `updated_at`: 6110 em 5 min = 5 execuções.
- CA1 usou o código `999901`, que não existia; tudo foi revertido.
- CA6 foi feito com `CRONTAB_ENABLE=false` no `.env`, que está no `.gitignore` e não é definido no
  ambiente do container, reiniciando só o worker. Depois o `.env` voltou ao original e o worker foi
  reiniciado, e o dispatcher subiu de novo.
- `resolve()` foi chamado por um `php -r` efêmero com bootstrap do Hyperf (precisa de
  `ApplicationInterface` para registrar o driver `pgsql`); nenhum arquivo foi criado no repo.
- Nenhum arquivo de código foi alterado.
**Objetivo:** validar em dev, sem navegador (`navegador: false`), e registrar antes/depois em
`validation.md`.
- Rodar juntos os testes criados: `LookupCacheServiceTest.php`, `SeedLayoutsAdminLookupTaskTest.php`,
  `CrontabConfigTest.php`; `composer analyse`.
- **CA1:** com `migrator` e `migrator-worker` no ar, inserir em `conciliador_web.layouts_admin` de
  dev um layout com código novo (ex.: `999901`, com os campos obrigatórios da tabela); em até 2 min
  conferir a linha em `lookup_cache` (`entity='layouts_admin'`, `label='999901'`) e
  `resolve('layouts_admin', 999901)` pelo container. Apagar o layout e conferir a remoção no ciclo
  seguinte. **Reverter**: garantir que o layout de teste não ficou no `conciliador_web` nem no cache.
- **CA2:** por 5 minutos, contar execuções no log `lookup-cache`/`runtime/logs/hyperf.log` dos dois
  containers e as mudanças de `max(updated_at)` em `lookup_cache` para `layouts_admin`: esperado 5
  execuções no total, não 10. Se o log só-quando-muda não deixar contar execuções sem mudança, usar
  `updated_at` (o `upsert` atualiza a cada execução).
- **CA6:** subir com `CRONTAB_ENABLE=false` num container e conferir que a tarefa não roda nele;
  voltar ao padrão.
- **CA7:** `php bin/hyperf.php migration:seed-lookups` e `... layouts_admin` imprimem como antes.
- **CA8:** repetir a medição do T1 (3 execuções, mediana) e preencher a tabela final.
**Critério de pronto:** CA1–CA8 verificados e registrados em `validation.md` com números; dados de
teste revertidos e listados.
**Dependências:** T1–T6.
**Cobre Critério de Aceite:** todos.
