# Validation: Atualizar o `lookup_cache` de `layouts_admin` a cada minuto

> Referência: [spec.md](./spec.md) · [tasks.md](./tasks.md)

## Medições de referência (antes)

Medido em 2026-11-23 (T1), dev, container `conciliador-migrator` (`APP_ENV=dev`), código do commit
`73f37b1` (antes de qualquer mudança desta spec). Comando executado pela trava do orquestrador
(`container.sh`), tempo de parede medido dentro do container com `microtime(true)` em volta de
`php bin/hyperf.php migration:seed-lookups layouts_admin` — inclui o boot do Hyperf, não só o
`seed()`.

| Cenário | Métrica | Execução 1 | Execução 2 | Execução 3 | Mediana |
|---|---|---|---|---|---|
| `migration:seed-lookups layouts_admin` | duração total (s) | 0,814 | 0,768 | 0,792 | **0,792** |
| `conciliador_web.layouts_admin` | linhas lidas / upsertadas | 1222 | 1222 | 1222 | 1222 |

Saída do comando (igual nas 3): `→ 1222 registro(s) upsertados para 'layouts_admin'.` (rc=0).
Houve uma 4ª execução antes das medições (teste de que o comando roda pela trava), com a mesma saída.
O seed é idempotente: as execuções só reescreveram as mesmas 1222 linhas de `lookup_cache`
(`entity='layouts_admin'`, `environment='dev'`); nenhum dado de teste inserido.

## Execução

### T1 — 2026-11-23
- Testes: `container.sh vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/LookupCacheServiceTest.php`
  → **OK (6 tests, 18 assertions)**, código atual sem alteração (caracterização).
- Observações: medição "antes" acima (RNF3/CA8: 0,792 s < 10 s). O comando `php bin/hyperf.php`
  funcionou pela trava (com `PHP_INI_SCAN_DIR=/tmp/confd`), sem precisar do contorno do pgsql.

### T2 — 2026-11-23
- Instalação: `hyperf/crontab` v3.1.67 (lock: só esse pacote adicionado). Em dev, instalado sem
  sincronizar o resto do `vendor/` ("1 install, 0 updates, 0 removals"); detalhes nas notas do T2.
- `container.sh php bin/hyperf.php` → rc 0, lista `crontab:run` e os `migration:*`.
- `docker restart conciliador-migrator conciliador-migrator-worker` → nos dois:
  `[INFO] Process[crontab-dispatcher.0] start.` e `Crontab dispatcher sleep ~39s`, sem exceção.
- `container.sh composer analyse` → 1 erro, anterior a esta spec (`config/autoload/async_queue.php:36`,
  `Hyperf\AsyncQueue\Handler\FailedHandler` não encontrado); nada novo em `crontab.php`/`processes.php`.
- Testes PHPUnit: não se aplicam ao T2 (os testes de configuração entram no T5).

### T3 — 2026-11-23
- Testes: `container.sh vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/LookupCacheServiceTest.php`
  → **OK (9 tests, 36 assertions)**: 6 de caracterização do T1 e 3 de `seedWithChanges`.
- `container.sh composer analyse` → só o erro que já existia (`config/autoload/async_queue.php:36`); nenhum novo.
- `container.sh vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --dry-run` nos arquivos da spec
  → limpo depois dos ajustes.
- CA7 (comando manual): `migration:seed-lookups layouts_admin` → `→ 1222 registro(s) upsertados para
  'layouts_admin'.`, mesmo texto do T1. `migration:seed-lookups` (todas) → roles 4, status 3,
  layouts_admin 1222, permissions 68, `Total: 1297`. O aviso `Deprecated` de
  `ylnwqm/hyperf-batch` vem do vendor e já existia. As duas execuções só reescreveram, de forma
  idempotente, as linhas que já estavam em `lookup_cache` (`environment='dev'`); nenhum dado de
  teste foi inserido.

### T4 — 2026-11-23
- Testes: `container.sh vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Crontab/SeedLayoutsAdminLookupTaskTest.php`
  → **OK (4 tests, 4 assertions)**. Cobre CA4 (erro logado e não relançado) e CA5 (sem mudança, nenhum
  `info`; com mudança, um `info` com as contagens).
- `container.sh composer analyse` → só o erro que já existia (`config/autoload/async_queue.php:36`); nenhum novo.
- `php-cs-fixer --dry-run` nos 2 arquivos novos → 0 ajustes.

### T5 — 2026-11-23
- Testes: `container.sh vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Config/CrontabConfigTest.php test/Cases/Unit/Crontab/WarningLevelCrontabLoggerTest.php test/Cases/Unit/Crontab/SeedLayoutsAdminLookupTaskTest.php`
  → **OK (10 tests, 24 assertions)** (3 de config — CA3, CA6 —, 3 do logger, 4 da tarefa do T4).
- `container.sh composer analyse` → só o erro que já existia (`config/autoload/async_queue.php:36`); nenhum novo.
- `php-cs-fixer --dry-run` nos 5 arquivos do T5 → 0 ajustes.
- Dev: `docker restart conciliador-migrator conciliador-migrator-worker` às 15:05:54 UTC → os dois com
  `Process[crontab-dispatcher.0] start.`, sem exceção nem warning do crontab; sem a linha debug
  "Crontab dispatcher sleep" (logger filtrado).
- Redis db 5: `hyperf/crontab-b7815145dd87205d8bd651a3ff7492243aaa928e-sv = eth0:FAC3A1FA2CE9` (MAC do
  `conciliador-migrator`; o worker é `5a:1a:1a:af:3a:c3`), TTL 90.
- `lookup_cache` (`layouts_admin`, `dev`, 1222 linhas), `min(updated_at) = max(updated_at)` em cada amostra:
  15:06:00 → 15:07:00 → 15:08:00 → 15:09:00 (amostras às 15:06:26, 15:07:16, 15:08:17, 15:09:17).
- Execuções por minuto (as duas instâncias gravam no mesmo `runtime/logs/hyperf.log`, volume do repo):
  2 linhas `sql` com `from "lookup_cache" where "entity" = 'layouts_admin' and "environment" = 'dev'`
  por minuto (o `pluck` e o `delete` de **uma** execução) em 15:06, 15:07 e 15:08 → 1 execução/minuto
  no conjunto (prévia do CA2). Nenhum log `info` da tarefa (sem mudança, RNF4).
- Achado: cada execução grava 6 linhas `sql.INFO` (~200 KB, medido em 15:06:0x: 201 917 bytes) no
  `hyperf.log` pelo `DbQueryExecutedListener`. Resolvido pela decisão B (2026-11-23), abaixo.
- Dados: nenhum dado de teste inserido; o seed só reescreveu as mesmas 1222 linhas.

#### T5 — decisão B (log SQL silenciado só na tarefa), 2026-11-23
- Testes: `container.sh vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Config/CrontabConfigTest.php test/Cases/Unit/Crontab/WarningLevelCrontabLoggerTest.php test/Cases/Unit/Crontab/SeedLayoutsAdminLookupTaskTest.php test/Cases/Unit/Listener/DbQueryExecutedListenerTest.php`
  → **OK (17 tests, 43 assertions)**: config 3, logger 3, tarefa 6 (2 novos: flag ligada durante o
  seed e removida depois, com sucesso e com exceção), listener 5 (2 novos: não loga com a flag, volta
  a logar sem ela).
- `container.sh composer analyse` → só o erro que já existia (`config/autoload/async_queue.php:36`).
- `php-cs-fixer --dry-run` nos 4 arquivos alterados → 0 ajustes.
- Dev: restart dos dois containers às 15:13:49 UTC. A trava passou para o `conciliador-migrator-worker`
  (`eth0:7233CC171E8F`); a do container antigo foi apagada no `WORKER_EXIT` do restart.

| Minuto (UTC) | Código | `updated_at` de `layouts_admin` (min = max, 1222 linhas) | Linhas `sql.INFO` no `hyperf.log` | Bytes no minuto |
|---|---|---|---|---|
| 15:13 | antes da decisão B | — | 6 | 201 918 |
| 15:14 | decisão B | 15:14:00 | 0 | 0 |
| 15:15 | decisão B | 15:15:00 | 0 | — |
| 15:16 | decisão B | 15:16:00 | 0 | — |

  (15:15 e 15:16: contadas só as linhas `sql` com `lookup_cache`; o grep do total no arquivo de 1,7 GB
  foi interrompido por lentidão.)
- Log SQL do resto da aplicação inalterado: `container.sh php bin/hyperf.php migration:seed-lookups layouts_admin`
  (15:18 UTC) → `→ 1222 registro(s) upsertados para 'layouts_admin'.`; o `hyperf.log` cresceu
  201 919 bytes, com 5 linhas `sql.INFO` de `lookup_cache` (e mais o `select` da origem).
- Dados: nenhum dado de teste; só reescritas idempotentes das mesmas 1222 linhas.

### T6 — 2026-11-23
- Só documentação e `.env.example`; sem código PHP alterado, então sem PHPUnit, sem `composer analyse` e sem restart.
- Conferência: `grep -rn CRONTAB_ENABLE docs/ai .env.example config` → `config/autoload/crontab.php`
  (leitura), `.env.example` e a seção "Lookup cache" de `docs/ai/architecture.md`. `docs/ai/workflows.md`
  só remete à seção, sem repetir a regra (regra de ouro do `CLAUDE.md`).
- As referências citadas na doc foram conferidas no código: `LayoutSource.php:55`, `CompanyLayoutSource.php:100` e
  `MigrationOrchestrator.php:78-83`.

### T7 — 2026-11-23 (validação final, dev, sem navegador)
- **Testes juntos:** `container.sh vendor/bin/phpunit -c phpunit.unit.xml.dist test/Cases/Unit/Service/LookupCacheServiceTest.php test/Cases/Unit/Crontab/SeedLayoutsAdminLookupTaskTest.php test/Cases/Unit/Crontab/WarningLevelCrontabLoggerTest.php test/Cases/Unit/Config/CrontabConfigTest.php test/Cases/Unit/Listener/DbQueryExecutedListenerTest.php`
  → **OK (26 tests, 79 assertions)** (CA3, CA4, CA5, CA6 em config e CA7 na parte unitária).
  `container.sh composer analyse` → só o erro pré-existente (`config/autoload/async_queue.php:36`).
- **CA2 (RF2, RNF2), 15:22–15:27 UTC:** `migrator` e `migrator-worker` no ar o tempo todo. Como a
  tarefa não loga nada quando não há mudança e o log SQL dela está silenciado, a contagem foi feita
  por `pg_stat_user_tables.n_tup_upd` de `lookup_cache` (cada seed faz upsert de 1222 linhas) e por
  `updated_at`:

  | Amostra (UTC) | `n_tup_upd` | Δ | `updated_at` (min = max) | Dono da trava |
  |---|---|---|---|---|
  | 15:22:30 | 33 069 | — | 15:22:00 | worker (`eth0:7233CC171E8F`) |
  | 15:23:31 | 34 291 | 1 222 | 15:23:00 | worker |
  | 15:24:31 | 35 513 | 1 222 | 15:24:00 | worker |
  | 15:25:31 | 36 735 | 1 222 | 15:25:00 | worker |
  | 15:26:32 | 37 957 | 1 222 | 15:26:00 | worker |
  | 15:27:32 | 39 179 | 1 222 | 15:27:00 | worker |

  Total em 5 minutos: 6 110 = **5 × 1222 → 5 execuções**, não 10. Nenhuma inserção ou remoção no período.
- **CA1 (RF1, RNF1):** código de teste `999901`, que não existia (pré-checagem: 0 no `conciliador_web`, 0 no cache).
  - 15:27:40: `insert` em `conciliador_web.layouts_admin` (container `postgres`) de
    `id 524ec38e-18f4-4628-b84a-2e31c03ff16e`, `name 'TESTE CA1 SPEC AGENDAMENTO'`, `type imp`.
  - 15:28:00: linha `(layouts_admin, 524ec38e-…, '999901', dev)` em `lookup_cache` → **atraso de 20 s**
    (≤ 2 min). Log: `lookup-cache.INFO: lookup_cache layouts_admin sincronizado
    {"total":1223,"inserted":1,"updated":0,"removed":0}` (CA5 em execução real).
    `LookupCacheService::resolve('layouts_admin', 999901)`, chamado pelo container (script `php -r`
    efêmero com bootstrap do Hyperf, sem arquivo no repo), devolveu `'524ec38e-18f4-4628-b84a-2e31c03ff16e'`.
  - 15:28:11: `delete` do layout. 15:29:00: a linha saiu do cache (**51 s**, no ciclo seguinte). Log:
    `{"total":1222,"inserted":0,"updated":0,"removed":1}`. Depois disso, `resolve(…, 999901)` → `NULL`.
- **CA6 (RF5):** às 15:29:17, `CRONTAB_ENABLE=false` foi acrescentado ao `.env` (o container não define
  essa variável; o `.env` está no `.gitignore`) e só o `conciliador-migrator-worker` foi reiniciado.
  O worker subiu só com `Process[queue.default.0] start.`, sem `crontab-dispatcher` (0 linhas no log).
  A trava foi para o `conciliador-migrator` (`eth0:9AC0DD506DFD`), e o seed seguiu sem pausa:
  `updated_at` 15:30:00 → 15:31:00, `n_tup_upd` +1222. **Volta ao padrão:** às 15:31:28 a linha foi
  removida do `.env` (`grep -c CRONTAB .env` → 0) e o worker foi reiniciado →
  `[INFO] Process[crontab-dispatcher.0] start.` de novo.
- **CA7 (RF6):** `migration:seed-lookups layouts_admin` → `→ 1222 registro(s) upsertados para
  'layouts_admin'.` (3×, rc 0). `migration:seed-lookups` → roles 4, status 3, layouts_admin 1222,
  permissions 68, `Seed concluído. Total: 1297 registro(s) upsertados.`, igual ao T3. O comando manual
  continua logando o SQL (medido no T5).
- **CA8 (RNF3):** mesmo método do T1 (`microtime(true)` em volta do comando, dentro do container, com o boot):
  0,772 / 0,764 / 0,730 s → mediana **0,764 s**.
- **Dados de teste revertidos:** layout `999901` (`id 524ec38e-18f4-4628-b84a-2e31c03ff16e`) inserido
  e apagado no `conciliador_web.layouts_admin`. Ao final: 0 linhas com `code = 999901` no
  `conciliador_web`, 0 linhas com `label = '999901'` no `lookup_cache` (todos os ambientes),
  `layouts_admin` = 1222 e cache `dev` = 1222, os mesmos totais do início. A linha
  `CRONTAB_ENABLE=false` saiu do `.env`. Os containers terminaram no padrão, os dois com o dispatcher.
  Os seeds manuais só reescreveram, de forma idempotente, as linhas que já existiam.

## Medições finais (depois)

| Cenário | Métrica | Antes | Depois | Meta | OK? |
|---|---|---|---|---|---|
| seed `layouts_admin` (comando) | duração mediana (s) | 0,792 | 0,764 | < 10 | sim |
| layout novo → `lookup_cache` | atraso (s) | indefinido (manual) | 20 | ≤ 120 | sim |
| layout removido → sai do cache | atraso (s) | indefinido (manual) | 51 | ≤ 120 | sim |
| execuções do seed em 5 min (2 containers) | contagem | 0 (manual) | 5 | = 5 | sim |
| linhas de log por minuto sem mudança | `info` da tarefa / crontab / `sql` | — | 0 / 0 / 0 | 0 | sim |
