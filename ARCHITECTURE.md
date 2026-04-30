# ARCHITECTURE.md - conciliador-migrator

## Proposito

`conciliador-migrator` e uma API HTTP em Hyperf/Swoole que migra dados de um
banco legado PostgreSQL para o banco de destino `conciliador_web`.

O desenho atual e **pull-mode**:

- o cliente informa qual database legado deve ser migrado;
- a API cria um job em `migration_jobs`;
- o job e executado por uma fila Redis;
- o worker conecta no legado, le as tabelas de origem via `Source` classes,
  transforma os registros e insere no `conciliador_web`;
- IDs legados sao mapeados para UUIDs novos em `migration_id_mappings`, o que
  permite retomar jobs e resolver FKs entre entidades.

Nao existe mais, no codigo atual, uma API publica de batch por entidade como
`POST /api/v1/migration/{entity}`. Essa arquitetura antiga ainda tem algumas
classes e tabelas auxiliares no repositorio, mas nao participa do fluxo vivo.

---

## Entrada HTTP

Rotas registradas manualmente em `config/routes.php`:

| Metodo | Rota | Auth | Finalidade |
|---|---|---|---|
| `GET` | `/` | Nao | Informacoes basicas do servico |
| `GET` | `/health` | Nao | Health check simples |

Rotas registradas por annotations em `MigrationJobController`:

| Metodo | Rota | Auth | Finalidade |
|---|---|---|---|
| `POST` | `/api/v1/migration/database` | Sim | Cria e enfileira um job pull-mode |
| `GET` | `/api/v1/migration/job/{jobId}` | Sim | Consulta status detalhado de um job |
| `GET` | `/api/v1/migration/jobs` | Sim | Lista jobs do contrato informado |

### Request para criar job

```http
POST /api/v1/migration/database
X-Api-Key: <MIGRATION_API_KEY>
X-Contract-Id: <contract-id>
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

## Arquitetura de Alto Nivel

```text
Client / operador
      |
      | POST /api/v1/migration/database
      | Headers: X-Api-Key, X-Contract-Id
      | Body: { "legacy_db": "..." }
      v
+-------------------------------------------------------+
| Hyperf HTTP Server (Swoole, porta 9501)               |
|                                                       |
| ApiTokenMiddleware                                    |
|   - compara X-Api-Key diretamente com MIGRATION_API_KEY|
|                                                       |
| RateLimitMiddleware                                   |
|   - contador Redis por contract/endpoint              |
|                                                       |
| MigrationJobController                                |
|   - valida legacy_db                                  |
|   - valida X-Contract-Id                              |
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
| tabelas legadas    |              | rules_sharings     |
| ainda existentes:  |              | layouts            |
| migration_batches  |              | companies          |
| migration_audit_*  |              | company_layout     |
+--------------------+              +--------------------+
```

---

## Bancos e Tabelas do Migrador

### `default`

Tabelas criadas pelas migrations do proprio migrador.

| Tabela | Status atual | Finalidade |
|---|---|---|
| `migration_jobs` | Ativa | Estado do job pull-mode, entidade atual, progresso por entidade, totais e erros |
| `migration_id_mappings` | Ativa | Mapeia `(entity, legacy_id, contract_id)` para `new_id`; base de idempotencia e FK |
| `lookup_cache` | Ativa | Cache local para dados estaticos do destino, como `roles`, `status`, `layouts_admin` e `permissions` |
| `migration_batches` | Legado/inativa | Sobrou do fluxo push-mode por batches; `MigrationBatchService` nao e chamado pelo fluxo atual |
| `migration_audit_logs` | Legado/inativa | Estrutura para auditoria por request do push-mode; `MigrationAuditService` nao e chamado pelo pull-mode |
| `migration_record_logs` | Legado/inativa | Logs por registro do push-mode; nao sao gerados pelo fluxo atual |

### `conciliador_web`

Banco de destino. O migrador insere diretamente nas tabelas de negocio usando
Query Builder (`Db::connection('conciliador_web')->table(...)->insert(...)`).

O fluxo ativo faz `INSERT` nas entidades cadastradas no registry. Existe tambem
um handler especial para `permission_users` via DELETE, mas a Source
correspondente esta desligada no registry.

### `legacy_database`

Conexao dinamica baseada em `config/autoload/databases.php`.
`LegacyConnectionFactory::connect($legacyDb)` troca apenas o campo `database`
da connection `legacy_database`, roda `SELECT 1` e devolve o nome da conexao.

Observacao: o `.env.example` menciona uma whitelist `LEGACY_DBS`, mas a
implementacao atual nao aplica essa whitelist; o nome recebido em `legacy_db`
e usado diretamente como database da connection legada.

---

## Fluxo de Job Pull-Mode

```text
POST /api/v1/migration/database
  |
  | 1. ApiTokenMiddleware
  |    - exige X-Api-Key
  |    - compara com MIGRATION_API_KEY
  |
  | 2. RateLimitMiddleware
  |    - usa Redis
  |    - chave: migration_rate:{contractId}:standard
  |
  | 3. MigrationJobController::dispatch()
  |    - le body.legacy_db
  |    - le X-Contract-Id
  |    - valida conexao no legado
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

Configuracao da fila:

- driver Redis (`hyperf/async-queue`);
- consumer registrado em `config/autoload/processes.php`;
- `processes` default: `1`;
- `concurrent.limit` default: `1`;
- `handle_timeout` default: `86400` segundos;
- `RunDatabaseMigrationJob::$maxAttempts = 3`.

---

## Pipeline de Entidade

Cada entidade do pull-mode e definida por uma classe em `app/PullMode/Source`.
Essas classes informam:

- nome logico da entidade (`entity()`);
- tabela de destino (`targetTable()`);
- SQL de leitura do legado (`sql()`);
- tamanho de chunk (`chunkSize()`);
- mapa de FKs legadas (`fkMap()`);
- estrategia de UUID (`idStrategy()`);
- se strings devem ser normalizadas (`normalizeStrings()`);
- se a tabela possui `contract_id` (`hasContractId()`);
- handler especial, quando o fluxo padrao nao serve (`specialHandler()`).

Pipeline padrao em `EntityMigrator`:

```text
para cada Source ativa:
  1. recuperar progresso salvo em migration_jobs.entity_progress
  2. usar last_id como cursor de retomada
  3. carregar linhas do legado via Source::paginate()
  4. aplicar Source::transformRow()
  5. filtrar duplicados por migration_id_mappings
  6. pre-aquecer cache de FKs com IdMappingService::prewarm()
  7. preparar registros:
     - normalizar strings
     - remover legacy_id
     - gerar UUID v4/v7 quando necessario
     - bcrypt em password quando existir
     - preencher created_at/updated_at
     - resolver legacy_*_id para *_id
  8. inserir em lote no conciliador_web via ParallelInsertService::insertBatch()
  9. gravar mappings da entidade em migration_id_mappings
 10. atualizar entity_progress e totals do job
```

### Paginacao

`AbstractLegacySource::paginate()` detecta se o SQL contem `:last_id` e
`:limit`.

- Se contem, usa keyset pagination.
- Se nao contem, carrega tudo de uma vez na primeira execucao.
- Em retomadas, quando `last_id` ja existe, queries sem keyset retornam vazio.

Isso significa que `chunkSize()` so tem efeito real nas Sources cujo SQL usa
`:last_id` e `:limit`.

### Validacao

Varias Sources declaram `validationRules()`, mas o pipeline atual nao chama
essas regras. Hoje, inconsistencias geralmente aparecem como erro de insert ou
erro de FK/resolucao, nao como erro de validacao previo por registro.

---

## Sources Ativas

Ordem atual em `EntityMetadataRegistry::sources()`:

| Ordem | Source | Entity | Origem principal | Destino | Observacoes |
|---:|---|---|---|---|---|
| 1 | `ContractSource` | `contracts` | `contrato` | `contracts` | Uma linha por database legado; usa `CURRENT_DATABASE()` como `legacy_id` |
| 2 | `UserSource` | `users` | `usuarios` | `users` | Ignora `suporte@integradorcontabil.net.br`; senha passa por bcrypt |
| 3 | `ContractUserSource` | `contract_users` | `usuarios` | `contract_user` | Handler especial de pivot; resolve role em `lookup_cache` |
| 4 | `PlanSource` | `plans` | `pcontasconc` | `plans` | Depende de `contracts` |
| 5 | `RulesSharingSource` | `rules_sharings` | `plano_contas` | `rules_sharings` | Depende de `contracts` |
| 6 | `LayoutSource` | `layouts` | `layout` + `layout_empresa` | `layouts` | Depende de `contracts`; pode referenciar outro layout |
| 7 | `PlanItemSource` | `plan_items` | `pcontasconc_item` | `plan_items` | Keyset pagination; nao possui `contract_id` |
| 8 | `CompanySource` | `companies` | `empresas` | `companies` | Depende de `contracts`, `plans`, `rules_sharings` |
| 9 | `CompanyLayoutSource` | `company_layout` | `layout_empresa` + `layout` | `company_layout` | Depende de `companies`, `layouts`, `layouts_admin`; nao possui `contract_id` |

### Export layouts

Antes de migrar as entidades, `ExportLayoutSyncService` garante que os codigos
`fk_layoutexp` usados em `layout_empresa` existam em `layouts_admin` no destino.
Para cada codigo encontrado, ele tambem garante um mapping:

```text
entity = layouts_admin
legacy_id = <codigo fk_layoutexp>
new_id = <uuid de layouts_admin>
contract_id = <X-Contract-Id>
```

Esse mapping e usado por `CompanyLayoutSource` para resolver
`legacy_layout_exp`.

---

## Sources Existentes Mas Desligadas

As classes abaixo existem, mas nao sao executadas enquanto nao forem
descomentadas/adicionadas em `EntityMetadataRegistry::sources()` na ordem correta
de dependencias:

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

Tambem existem Sources que nao aparecem no registry atual:

| Source | Entity | Destino |
|---|---|---|
| `IgnoredConciliationTermSource` | `ignored_conciliation_terms` | `ignored_conciliation_terms` |
| `ConfrontationConciliationSource` | `confrontation_conciliations` | `confrontation_conciliations` |

---

## Handlers Especiais

### `contract_users_pivot`

Usado por `ContractUserSource`.

Fluxo:

1. le usuarios do legado;
2. resolve `legacy_user_id` em `users`;
3. resolve `legacy_contract_id` em `contracts`;
4. resolve `legacy_role_id` (`owner` ou `user`) em `lookup_cache.roles`;
5. insere em `contract_user` com `insertOrIgnore()`;
6. nao grava `migration_id_mappings`, porque pivot nao tem ID proprio.

### `user_permissions_delete`

Implementado em `EntityMigrator`, mas a Source esta desligada.

Fluxo previsto:

1. resolve o contrato atual;
2. le `usuario_permissao`;
3. resolve usuarios migrados;
4. apaga de `permission_users` os registros daquele contrato/usuario.

E idempotente: deletar novamente nao falha quando nao ha registros.

---

## Idempotencia e Retomada

O sistema usa duas camadas de idempotencia:

1. `migration_id_mappings`
   - evita inserir novamente registros cujo `(entity, legacy_id, contract_id)`
     ja tem `new_id`;
   - resolve FKs entre entidades migradas em momentos diferentes.

2. `migration_jobs.entity_progress`
   - guarda status por entidade;
   - guarda `last_id`;
   - guarda acumulados `inserted`, `failed`, `skipped`;
   - permite retry do job sem recomecar tudo.

Comportamentos importantes:

| Cenario | Comportamento |
|---|---|
| Reprocessar registro ja mapeado | Registro entra em `skipped` e nao e inserido de novo |
| Reprocessar entidade `completed` | Orchestrator pula a entidade |
| Job falha em uma entidade | Entidade e marcada como `failed`; o orchestrator continua as proximas |
| Algum chunk falha no insert | `inserted`/`failed` sao acumulados; o erro fica em `entity_progress` |
| Retry da fila | Seguro em tese, porque o job consulta progresso e mappings |

Observacao operacional: `IdMappingService::storeBatch()` grava mappings quando o
insert retorna sucesso parcial. Como o insert e feito em chunks paralelos, se um
chunk falhar e outro inserir, os mappings gerados para o batch preparado podem
nao distinguir exatamente quais registros pertenciam ao chunk que falhou. Ao
ampliar uso em alto volume, vale revisar esse ponto.

---

## Lookup Cache

`LookupCacheService` popula a tabela `lookup_cache` lendo dados de
`conciliador_web`.

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
- `CompanyLayoutSource` usa mappings de `layouts_admin`, alimentados por
  `ExportLayoutSyncService`.

---

## Seguranca

### Implementacao atual

- `X-Api-Key` e obrigatorio nos endpoints de migracao.
- O valor recebido e comparado diretamente com `MIGRATION_API_KEY`.
- `X-Contract-Id` e obrigatorio para criar/listar jobs e e usado como tenant
  logico nos mappings e no progresso.
- `RateLimitMiddleware` usa Redis por minuto.

### O que ainda nao esta conectado

`ApiKeyService` possui suporte a payload AES-256-GCM no formato:

```text
v1.<iv>.<tag>.<ciphertext>
```

Ele tambem suporta `MIGRATION_API_KEYS`, `contract_id`, `user_id` e `exp` no
payload descriptografado. Porem, no codigo atual, `ApiTokenMiddleware` nao usa
`ApiKeyService`; portanto essas variaveis e esse formato ainda nao fazem parte
do fluxo real.

---

## Status e Erros

### Status de job

`migration_jobs.status` pode assumir:

- `queued`
- `processing`
- `completed`
- `completed_with_errors`
- `failed`

`entity_progress` e um JSON por entidade com campos como:

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

### Tratamento de excecoes

`AppExceptionHandler` renderiza `ApiException` como RFC 7807
(`application/problem+json`). Excecoes nao mapeadas viram HTTP 500 generico.

`DiscordNotificationService::notifyException()` e chamado pelo handler global
quando notificacoes estao habilitadas. `notifyMigration()` existe, mas nao e
chamado no fluxo atual.

---

## UUID

O pipeline usa `RecordPreparation::recordPrepGenerateId()`:

| Estrategia | Uso |
|---|---|
| `uuid4` | Padrao para a maioria das entidades |
| `uuid7` | Disponivel para Sources de alto volume, como `ImportRecordSource` e `ConfrontationRecordSource`, atualmente desligadas |

Se o registro ja vier com `id`, o pipeline preserva esse valor.

---

## Componentes Legados / Inativos

Itens presentes no repositorio que nao fazem parte do fluxo pull-mode atual:

- `MigrationBatchService` e tabela `migration_batches`;
- `MigrationAuditService`, `migration_audit_logs` e `migration_record_logs`;
- schemas Swagger de batch por entidade (`MigrationBatchRequest`,
  `SyncMigrationResponse`, `AsyncMigrationResponse`, `MigrationBatchStatus`,
  `IdMappingRequest`, `IdMappingResponse`);
- `IndexController`, pois `/` e registrado por closure em `routes.php`;
- `BatchTooLargeException`, `EmptyBatchException`, `UnauthorizedException`;
- dependencia `firebase/php-jwt`;
- dependencia `ylnwqm/hyperf-batch`;
- dependencia/config de `hyperf/cache`, salvo se for usada por infraestrutura
  futura;
- pacote `hyperf/rate-limit`, pois o rate limit atual e middleware proprio com
  Redis;
- documentacao antiga que fala em endpoints por entidade e batches enviados pelo
  cliente.

Antes de remover qualquer item, confirme se nao ha uso planejado em branch ou
deploy externo. Algumas classes inativas podem estar guardadas como base para
reativar entidades de alto volume.

---

## Regras Para Adicionar Uma Nova Entidade

1. Criar uma nova classe em `app/PullMode/Source` estendendo
   `AbstractLegacySource`.
2. Definir `entity()`, `targetTable()` e `sql()`.
3. Usar aliases no SQL com nomes finais esperados no destino.
4. Expor IDs legados como `legacy_id` e FKs como `legacy_<nome>_id`.
5. Definir `fkMap()` apontando cada FK legada para a entidade do mapping.
6. Usar keyset pagination (`:last_id`, `:limit`) para tabelas grandes.
7. Sobrescrever `transformRow()` apenas para regras que nao cabem no SQL.
8. Sobrescrever `hasContractId()` para `false` quando a tabela destino nao tiver
   coluna `contract_id`.
9. Escolher `uuid7` para entidades de volume muito alto, se fizer sentido para o
   indice do destino.
10. Adicionar a Source em `EntityMetadataRegistry::sources()` depois de todas as
    dependencias de FK.

Nao paralelizar entidades no orchestrator: a ordem do registry e parte do
contrato de consistencia por FK.
