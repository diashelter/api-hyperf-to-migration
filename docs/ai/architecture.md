# Architecture — conciliador-migrator

## Propósito

`conciliador-migrator` é uma API HTTP em Hyperf/Swoole que migra dados de um banco legado PostgreSQL para o banco de destino `conciliador_web`.

O desenho atual é **pull-mode**:

- o cliente informa qual database legado deve ser migrado;
- a API cria um job em `migration_jobs`;
- o job é executado por uma fila Redis;
- o worker conecta no legado, lê as tabelas de origem via classes `Source`, transforma os registros e insere no `conciliador_web`;
- IDs legados são mapeados para UUIDs novos em `migration_id_mappings`, o que permite retomar jobs e resolver FKs entre entidades.

Não existe mais, no código atual, uma API pública de batch por entidade como `POST /api/v1/migration/{entity}`. Essa arquitetura antiga ainda tem algumas classes e tabelas auxiliares no repositório, mas não participa do fluxo vivo.

---

## Entrada HTTP

Rotas registradas manualmente em `config/routes.php`:

| Método | Rota | Auth | Finalidade |
|---|---|---|---|
| `GET` | `/` | Não | Informações básicas do serviço |
| `GET` | `/health` | Não | Health check simples |

Rotas registradas por annotations em `MigrationJobController`:

| Método | Rota | Auth | Finalidade |
|---|---|---|---|
| `POST` | `/api/v1/migration/database` | Sim | Cria e enfileira um job pull-mode |
| `GET` | `/api/v1/migration/job/{jobId}` | Sim | Consulta status detalhado de um job |
| `GET` | `/api/v1/migration/jobs` | Sim | Lista jobs recentes; aceita filtro opcional `legacy_db` |

### Request para criar job

```http
POST /api/v1/migration/database
X-Api-Key: <MIGRATION_API_KEY>
Content-Type: application/json

{
  "legacy_db": "cont_focons"
}
```

Resposta esperada:

```json
{
  "job_id": "260439b0-9fd0-4b01-a931-ecf2677ed972",
  "legacy_db": "cont_focons",
  "status": "queued",
  "status_url": "/api/v1/migration/job/260439b0-9fd0-4b01-a931-ecf2677ed972"
}
```

---

## Arquitetura de alto nível

```text
Client / operador
      |
      | POST /api/v1/migration/database
      | Headers: X-Api-Key
      | Body: { "legacy_db": "..." }
      v
+-------------------------------------------------------+
| Hyperf HTTP Server (Swoole, porta 9501)               |
|                                                       |
| ApiTokenMiddleware                                    |
|   - compara X-Api-Key com MIGRATION_API_KEY           |
|                                                       |
| RateLimitMiddleware                                   |
|   - contador Redis por contract/endpoint              |
|                                                       |
| MigrationJobController                                |
|   - valida legacy_db                                  |
|   - usa legacy_db como namespace interno da migracao  |
|   - faz smoke test da conexao legada                  |
|   - cria migration_jobs                               |
|   - enfileira RunDatabaseMigrationJob                 |
+---------------------------+---------------------------+
                            |
                            | Redis async-queue
                            v
+-------------------------------------------------------+
| ConsumerProcess / RunDatabaseMigrationJob             |
|                                                       |
| MigrationOrchestrator                                 |
|   - conecta no banco legado                           |
|   - marca job como processing                         |
|   - sincroniza layouts_admin de exportacao            |
|   - percorre EntityMetadataRegistry::sources()        |
|                                                       |
| EntityMigrator                                        |
|   - pagina/carrega registros da Source                |
|   - transforma linhas                                 |
|   - filtra duplicados por migration_id_mappings       |
|   - resolve FKs legadas                               |
|   - normaliza dados e gera UUID                       |
|   - insere em conciliador_web                         |
|   - grava mappings                                    |
+---------------------------+---------------------------+
                            |
          +-----------------+-----------------+
          |                                   |
          v                                   v
+--------------------+              +--------------------+
| default            |              | conciliador_web    |
| schema do migrador |              | banco destino      |
|                    |              |                    |
| migration_jobs     |              | contracts          |
| migration_id_      |              | users              |
|   mappings         |              | contract_user      |
| lookup_cache       |              | plans              |
|                    |              | plan_items         |
| tabelas legadas:   |              | rules_sharings     |
| migration_batches  |              | layouts            |
| migration_audit_*  |              | companies          |
|                    |              | company_layout     |
+--------------------+              +--------------------+
```

---

## Bancos e tabelas do migrador

### `default`

Tabelas criadas pelas migrations do próprio migrador.

| Tabela | Status atual | Finalidade |
|---|---|---|
| `migration_jobs` | Ativa | Estado do job pull-mode, entidade atual, progresso por entidade, totais e erros |
| `migration_id_mappings` | Ativa | Mapeia `(entity, legacy_id, contract_id)` para `new_id`; base de idempotência e FK |
| `lookup_cache` | Ativa | Cache local para dados estáticos do destino, como `roles`, `status`, `layouts_admin` e `permissions` |
| `migration_batches` | Legado/inativa | Sobrou do fluxo push-mode por batches; `MigrationBatchService` não é chamado pelo fluxo atual |
| `migration_audit_logs` | Legado/inativa | Estrutura para auditoria por request do push-mode; `MigrationAuditService` não é chamado pelo pull-mode |
| `migration_record_logs` | Legado/inativa | Logs por registro do push-mode; não são gerados pelo fluxo atual |

### `conciliador_web`

Banco de destino. O migrador insere diretamente nas tabelas de negócio usando Query Builder (`Db::connection('conciliador_web')->table(...)->insert(...)`).

O fluxo ativo faz `INSERT` nas entidades cadastradas no registry. Existe também um handler especial para `permission_users` via DELETE, mas a Source correspondente está desligada no registry.

### `legacy_database`

Conexão dinâmica baseada em `config/autoload/databases.php`. `LegacyConnectionFactory::connect($legacyDb)` troca apenas o campo `database` da connection `legacy_database`, roda `SELECT 1` e devolve o nome da conexão.

Observação: o `.env.example` menciona uma whitelist `LEGACY_DBS`, mas a implementação atual não aplica essa whitelist; o nome recebido em `legacy_db` é usado diretamente como database da connection legada.

---

## Fluxo de job pull-mode

```text
POST /api/v1/migration/database
  |
  | 1. ApiTokenMiddleware
  |    - exige X-Api-Key
  |    - compara com MIGRATION_API_KEY
  |
  | 2. RateLimitMiddleware
  |    - usa Redis
  |    - chave: migration_rate:{legacy_db}:standard
  |
  | 3. MigrationJobController::dispatch()
  |    - lê body.legacy_db
  |    - usa legacy_db como migration_scope
  |    - valida conexão no legado
  |    - cria migration_jobs(status=queued)
  |    - push RunDatabaseMigrationJob(jobId)
  |    - retorna 202 + Location
  v
Redis async-queue
  |
  | 4. RunDatabaseMigrationJob::handle()
  |    - resolve MigrationOrchestrator no container
  |
  | 5. MigrationOrchestrator::run()
  |    - carrega status do job
  |    - conecta no legado
  |    - markProcessing()
  |    - ExportLayoutSyncService::sync()
  |    - percorre EntityMetadataRegistry::sources()
  |    - atualiza current_entity
  |    - chama EntityMigrator::migrate()
  |    - continua mesmo quando uma entidade falha
  |    - markCompleted() ou completed_with_errors
```

Configuração da fila:

- driver Redis (`hyperf/async-queue`);
- consumer registrado em `config/autoload/processes.php`;
- `processes` default: `1`;
- `concurrent.limit` default: `1`;
- `handle_timeout` default: `86400` segundos;
- `RunDatabaseMigrationJob::$maxAttempts = 3`.

---

## Pipeline de entidade

Cada entidade do pull-mode é definida por uma classe em `app/PullMode/Source`. Essas classes informam:

- nome lógico da entidade (`entity()`);
- tabela de destino (`targetTable()`);
- SQL de leitura do legado (`sql()`);
- tamanho de chunk (`chunkSize()`);
- mapa de FKs legadas (`fkMap()`);
- estratégia de UUID (`idStrategy()`);
- se strings devem ser normalizadas (`normalizeStrings()`);
- se a tabela possui `contract_id` (`hasContractId()`);
- handler especial, quando o fluxo padrão não serve (`specialHandler()`).

Pipeline padrão em `EntityMigrator` (producer/consumer overlap via `Swoole\Coroutine\Channel`):

```text
para cada Source ativa:
  1. recuperar progresso salvo em migration_jobs.entity_progress
  2. usar last_id como cursor de retomada
  3. abrir Channel(2) e disparar 2 corrotinas em paralelo:

     (producer)                           (consumer)
     ─ paginate via Source::paginate      ─ pop(channel)
     ─ aplicar Source::transformRow       ─ filterDuplicates (resolveMany)
     ─ push(channel)                      ─ restoreRecordsWithMissingTargets
                                          ─ reuseExistingUsersByEmail (users)
                                          ─ prewarmMulti (1 query batch p/ N FKs)
                                          ─ recordPrepPrepare
                                          ─ insertBatch ou copyBatch (Source::useCopy)
                                          ─ storeBatch (paralelo, MIGRATION_MAPPING_COROUTINES)
                                          ─ updateEntityProgress
  4. aguardar ambas corrotinas (Parallel::wait)
  5. atualizar status e totals do job
```

Ganho: enquanto o consumer faz `INSERT/COPY` no destino + `storeBatch` no schema do migrador, o producer já está lendo a próxima página do legado. O `Channel(2)` gera backpressure natural.

### Paginação

`AbstractLegacySource::paginate()` detecta se o SQL contém `:last_id` e `:limit`.

- Se contém, usa keyset pagination.
- Se não contém, carrega tudo de uma vez na primeira execução e loga warning em `migration-source` (canal padrão).
- Em retomadas, quando `last_id` já existe, queries sem keyset retornam vazio.

Isso significa que `chunkSize()` só tem efeito real nas Sources cujo SQL usa `:last_id` e `:limit`. Toda Source nova **deve** implementar keyset (ver `RuleSource` ou `ImportRecordSource` como referência).

### COPY FROM STDIN para alto volume

Sources de alto volume sobrescrevem `useCopy(): bool` e usam `ParallelInsertService::copyBatch()` quando `MIGRATION_USE_COPY=true`. O caminho preferencial usa `COPY FROM STDIN` pela extensão nativa `pgsql` (`MIGRATION_COPY_DRIVER=native`), evitando `PDO::pgsqlCopyFromArray`/`pgsqlCopyFromFile`, que travaram no ambiente Docker atual em `ClientRead`.

Se a imagem ainda não tiver `php84-pgsql` carregado, `copyBatch()` não tenta o COPY via PDO: ele faz fallback para bulk insert multi-row, com chunks calibrados por `MIGRATION_BULK_INSERT_PARAMETER_LIMIT` e paralelismo por `MIGRATION_COPY_COROUTINES`. Isso mantém a migração andando sem o gargalo/risco de deadlock do PDO, embora sem o ganho máximo do COPY real.

Restrições do caminho COPY:

- `COPY FROM` respeita constraints e triggers do PostgreSQL, mas não retorna linhas; o pipeline já gera UUID e resolve FKs em PHP, então não precisamos de `RETURNING`.
- Cada chunk roda de forma atômica; em falha, o chunk entra em `failed` e o caller não grava `migration_id_mappings` para ele.
- Tipos: booleanos viram `t`/`f`, arrays/objects viram JSON; strings têm `\`, `\n`, `\r`, `\t` escapados.
- Para ativar COPY real na imagem, `Dockerfile` e `dev.Dockerfile` instalam `php84-pgsql`; reconstrua os containers após alterar a imagem.

Sources atualmente com `useCopy()` overridden: `ImportRecordSource`, `ConfrontationRecordSource`, `RuleSource`. Elas usam o caminho de alto volume por padrão; desligue com `MIGRATION_USE_COPY=false` se precisar voltar ao `insertBatch()` padrão.

### Dimensionamento de pool e coroutines

| Connection | min / max | Observação |
|---|---|---|
| `default` | 8 / 64 | Recebe escrita paralela de `storeBatch` (`MIGRATION_MAPPING_COROUTINES` chunks simultâneos) |
| `conciliador_web` | 8 / 64 | Recebe `insertBatch` paralelo (`MIGRATION_MAX_COROUTINES`) ou `copyBatch` por `MIGRATION_COPY_CHUNK_SIZE`/`MIGRATION_COPY_COROUTINES` |
| `legacy_database` | 4 / 32 | Leitura keyset sequencial; pool maior só ajuda em concorrência futura |

Regra de ouro: `MIGRATION_MAX_COROUTINES + MIGRATION_MAPPING_COROUTINES + margem` deve caber em `max_connections` da connection respectiva quando uma Source usa `insertBatch`. Em `copyBatch` nativo, as conexões `pgsql` não usam o pool Hyperf, então considere `MIGRATION_COPY_COROUTINES + MIGRATION_MAPPING_COROUTINES + margem`. No fallback bulk insert, a escrita usa o pool `conciliador_web`.

Variáveis relevantes:

```env
MIGRATION_CHUNK_SIZE=1000
MIGRATION_COPY_CHUNK_SIZE=5000
MIGRATION_COPY_COROUTINES=2
MIGRATION_COPY_DRIVER=native       # native=ext pgsql; bulk_insert=sem COPY
MIGRATION_BULK_INSERT_PARAMETER_LIMIT=60000
MIGRATION_MAX_COROUTINES=16        # paralelismo de insertBatch por página
MIGRATION_MAPPING_CHUNK_SIZE=1000
MIGRATION_MAPPING_COROUTINES=4     # paralelismo de upsert em migration_id_mappings
MIGRATION_ID_MAPPING_CACHE_SKIP=rules,import_records,confrontation_records
MIGRATION_USE_COPY=true            # caminho de alto volume nos sources maiores
```

### Validação

Várias Sources declaram `validationRules()`, mas o pipeline atual não chama essas regras. Hoje, inconsistências geralmente aparecem como erro de insert ou erro de FK/resolução, não como erro de validação prévio por registro.

---

## Sources ativas

Ordem atual em `EntityMetadataRegistry::sources()`:

| Ordem | Source | Entity | Origem principal | Destino | Observações |
|---:|---|---|---|---|---|
| 1 | `ContractSource` | `contracts` | `contrato` | `contracts` | Uma linha por database legado; usa `CURRENT_DATABASE()` como `legacy_id` |
| 2 | `UserSource` | `users` | `usuarios` | `users` | Ignora `suporte@integradorcontabil.net.br`; senha passa por bcrypt |
| 3 | `ContractUserSource` | `contract_users` | `usuarios` | `contract_user` | Handler especial de pivot; resolve role em `lookup_cache` |
| 4 | `PlanSource` | `plans` | `pcontasconc` | `plans` | Depende de `contracts` |
| 5 | `RulesSharingSource` | `rules_sharings` | `plano_contas` | `rules_sharings` | Depende de `contracts` |
| 6 | `LayoutSource` | `layouts` | `layout` + `layout_empresa` | `layouts` | Depende de `contracts`; pode referenciar outro layout |
| 7 | `PlanItemSource` | `plan_items` | `pcontasconc_item` | `plan_items` | Keyset pagination; não possui `contract_id` |
| 8 | `CompanySource` | `companies` | `empresas` | `companies` | Depende de `contracts`, `plans`, `rules_sharings` |
| 9 | `CompanyLayoutSource` | `company_layout` | `layout_empresa` + `layout` | `company_layout` | Depende de `companies`, `layouts`, `layouts_admin`; não possui `contract_id` |

### Export layouts

Antes de migrar as entidades, `ExportLayoutSyncService` garante que os códigos `fk_layoutexp` usados em `layout_empresa` existam em `layouts_admin` no destino. Para cada código encontrado, ele também garante um mapping:

```text
entity = layouts_admin
legacy_id = <codigo fk_layoutexp>
new_id = <uuid de layouts_admin>
contract_id = <legacy_db>
```

Esse mapping é usado por `CompanyLayoutSource` para resolver `legacy_layout_exp`.

---

## Sources existentes mas desligadas

As classes abaixo existem, mas não são executadas enquanto não forem descomentadas/adicionadas em `EntityMetadataRegistry::sources()` na ordem correta de dependências:

| Source | Entity | Destino |
|---|---|---|
| `CompanyLayoutFixedAccountSource` | `company_layout_fixed_accounts` | `company_layout_fixed_accounts` |
| `PeopleSource` | `peoples` | `peoples` |
| `PeopleVinculatedSource` | `people_vinculated` | `people_vinculated` |
| `ImportSource` | `imports` | `imports` |
| `ImportSessionSource` | `import_sessions` | `import_sessions` |
| `ImportRecordSource` | `import_records` | `import_records` |
| `RuleSource` | `rules` | `rules` |
| `ConfrontationSource` | `confrontations` | `confrontations` |
| `ConfrontationRecordSource` | `confrontation_records` | `confrontation_records` |
| `UserCompanyRestrictionSource` | `user_company_restrictions` | `user_company_restrictions` |
| `UserPermissionSource` | `user_permissions` | `permission_users` |

Também existem Sources que não aparecem no registry atual:

| Source | Entity | Destino |
|---|---|---|
| `IgnoredConciliationTermSource` | `ignored_conciliation_terms` | `ignored_conciliation_terms` |
| `ConfrontationConciliationSource` | `confrontation_conciliations` | `confrontation_conciliations` |

---

## Handlers especiais

### `contract_users_pivot`

Usado por `ContractUserSource`.

Fluxo:

1. lê usuários do legado;
2. resolve `legacy_user_id` em `users`;
3. resolve `legacy_contract_id` em `contracts`;
4. resolve `legacy_role_id` (`owner` ou `user`) em `lookup_cache.roles`;
5. filtra vínculos já existentes em `contract_user` por `(contract_id, user_id)`;
6. insere os vínculos restantes com `insertOrIgnore()`;
7. não grava `migration_id_mappings`, porque pivot não tem ID próprio.

O filtro explícito evita duplicidade mesmo quando o destino não possui constraint
única no pivot. Uma unique constraint no banco continua sendo a garantia mais
forte contra jobs concorrentes.

### `user_permissions_delete`

Implementado em `EntityMigrator`, mas a Source está desligada.

Fluxo previsto:

1. resolve o contrato atual;
2. lê `usuario_permissao`;
3. resolve usuários migrados;
4. apaga de `permission_users` os registros daquele contrato/usuário.

É idempotente: deletar novamente não falha quando não há registros.

---

## Idempotência e retomada

O sistema usa duas camadas de idempotência:

1. `migration_id_mappings`
   - evita inserir novamente registros cujo `(entity, legacy_id, contract_id)` já tem `new_id`;
   - resolve FKs entre entidades migradas em momentos diferentes.

2. `migration_jobs.entity_progress`
   - guarda status por entidade;
   - guarda `last_id`;
   - guarda acumulados `inserted`, `failed`, `skipped`;
   - permite retry do job sem recomeçar tudo.

Comportamentos importantes:

| Cenário | Comportamento |
|---|---|
| Reprocessar registro já mapeado | Registro entra em `skipped` e não é inserido de novo |
| Mapping existe, mas a linha do destino foi removida | O pipeline detecta `new_id` ausente na tabela alvo, reinsere o registro e atualiza o mapping |
| Reprocessar entidade `completed` | Orchestrator pula a entidade |
| Job falha em uma entidade | Entidade é marcada como `failed`; o orchestrator continua as próximas |
| Algum chunk falha no insert | `inserted`/`failed` são acumulados; o erro fica em `entity_progress` |
| Retry da fila | Seguro em tese, porque o job consulta progresso e mappings |

Observação operacional: `IdMappingService::storeBatch()` grava mappings quando o insert retorna sucesso parcial. Como o insert é feito em chunks paralelos, se um chunk falhar e outro inserir, os mappings gerados para o batch preparado podem não distinguir exatamente quais registros pertenciam ao chunk que falhou. Ao ampliar uso em alto volume, vale revisar esse ponto.

---

## Lookup cache

`LookupCacheService` popula a tabela `lookup_cache` lendo dados de `conciliador_web`.

Comando:

```bash
php bin/hyperf.php migration:seed-lookups
php bin/hyperf.php migration:seed-lookups roles
```

Entidades suportadas:

- `roles`
- `status`
- `layouts_admin`
- `permissions`

Uso atual:

- `ContractSource` resolve `status_contract` usando `lookup_cache.status`;
- `ContractUserSource` resolve role `owner`/`user` usando `lookup_cache.roles`;
- `CompanyLayoutSource` usa mappings de `layouts_admin`, alimentados por `ExportLayoutSyncService`.

---

## Segurança

### Implementação atual

- `X-Api-Key` é obrigatório nos endpoints de migração.
- O valor recebido é comparado diretamente com `MIGRATION_API_KEY`.
- `X-Contract-Id` não é mais necessário no pull-mode atual.
- `legacy_db` é usado como namespace interno da migração nas tabelas locais (`migration_jobs.contract_id` e `migration_id_mappings.contract_id`).
- O UUID real usado como `contract_id` nas tabelas de destino vem do registro inserido em `contracts`, mapeado por `entity=contracts` e `legacy_id=<legacy_db>`.
- `RateLimitMiddleware` usa Redis por minuto.

### O que ainda não está conectado

`ApiKeyService` possui suporte a payload AES-256-GCM no formato:

```text
v1.<iv>.<tag>.<ciphertext>
```

Ele também suporta `MIGRATION_API_KEYS`, `contract_id`, `user_id` e `exp` no payload descriptografado. Porém, no código atual, `ApiTokenMiddleware` não usa `ApiKeyService`; portanto essas variáveis e esse formato ainda não fazem parte do fluxo real.

---

## Status e erros

### Status de job

`migration_jobs.status` pode assumir:

- `queued`
- `processing`
- `completed`
- `completed_with_errors`
- `failed`

`entity_progress` é um JSON por entidade com campos como:

```json
{
  "companies": {
    "status": "completed",
    "last_id": "123",
    "inserted": 100,
    "failed": 0,
    "skipped": 5,
    "started_at": "2026-04-29 01:00:16",
    "finished_at": "2026-04-29 01:04:28"
  }
}
```

### Tratamento de exceções

`AppExceptionHandler` renderiza `ApiException` como RFC 7807 (`application/problem+json`). Exceções não mapeadas viram HTTP 500 genérico.

`DiscordNotificationService::notifyException()` é chamado pelo handler global quando notificações estão habilitadas. `notifyMigration()` existe, mas não é chamado no fluxo atual.

---

## UUID

O pipeline usa `RecordPreparation::recordPrepGenerateId()`:

| Estratégia | Uso |
|---|---|
| `uuid7` | Padrão para todos os UUIDs gerados pela aplicação |

Se o registro já vier com `id`, o pipeline preserva esse valor.

---

## Componentes legados / inativos

Itens presentes no repositório que não fazem parte do fluxo pull-mode atual:

- `MigrationBatchService` e tabela `migration_batches`;
- `MigrationAuditService`, `migration_audit_logs` e `migration_record_logs`;
- schemas Swagger de batch por entidade (`MigrationBatchRequest`, `SyncMigrationResponse`, `AsyncMigrationResponse`, `MigrationBatchStatus`, `IdMappingRequest`, `IdMappingResponse`);
- `IndexController`, pois `/` é registrado por closure em `routes.php`;
- `BatchTooLargeException`, `EmptyBatchException`, `UnauthorizedException`;
- dependência `firebase/php-jwt`;
- dependência `ylnwqm/hyperf-batch`;
- dependência/config de `hyperf/cache`, salvo se for usada por infraestrutura futura;
- pacote `hyperf/rate-limit`, pois o rate limit atual é middleware próprio com Redis;
- documentação antiga que fala em endpoints por entidade e batches enviados pelo cliente.

Antes de remover qualquer item, confirme se não há uso planejado em branch ou deploy externo. Algumas classes inativas podem estar guardadas como base para reativar entidades de alto volume.

---

## Regras para adicionar uma nova entidade

Veja `docs/ai/workflows.md` na seção "Adicionar uma nova entidade ao pull-mode" para o passo a passo completo.

Resumo: criar Source em `app/PullMode/Source`, definir `entity()`/`targetTable()`/`sql()`, configurar `fkMap()`, registrar em `EntityMetadataRegistry::sources()` **depois** de todas as dependências. **Não paralelize entidades** — a ordem do registry é parte do contrato de consistência por FK.
