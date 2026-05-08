# Workflows — comandos e operação

Todos os comandos rodam **dentro de containers Docker**. Nunca rode `php`, `composer` ou `psql` direto no host.

---

## Infraestrutura

```bash
docker compose up -d                          # sobe todos os containers
docker compose down                           # derruba tudo
docker compose logs -f migrator               # tail dos logs da API
docker compose logs -f migrator-postgres      # tail do banco
```

---

## Aplicação (dentro de `conciliador-migrator`)

```bash
docker exec conciliador-migrator composer test          # suíte completa (co-phpunit)
docker exec conciliador-migrator composer test:unit     # só unit tests
docker exec conciliador-migrator composer cs-fix        # PHP-CS-Fixer
docker exec conciliador-migrator composer analyse       # PHPStan (level 0, 300M)

docker exec conciliador-migrator php bin/hyperf.php migrate
docker exec conciliador-migrator php bin/hyperf.php migrate:status

# Popular lookup_cache a partir do conciliador_web
docker exec conciliador-migrator php bin/hyperf.php migration:seed-lookups
docker exec conciliador-migrator php bin/hyperf.php migration:seed-lookups roles
```

---

## Banco (dentro de `conciliador-migrator-postgres`)

```bash
docker exec -it conciliador-migrator-postgres psql -U conciliador -d conciliador

# Status dos jobs recentes
docker exec conciliador-migrator-postgres psql -U conciliador -d conciliador -c \
  "SELECT id, legacy_db, status, current_entity FROM migration_jobs ORDER BY created_at DESC LIMIT 10;"

# Mappings recentes de um contrato/legacy_db
docker exec conciliador-migrator-postgres psql -U conciliador -d conciliador -c \
  "SELECT entity, legacy_id, new_id FROM migration_id_mappings WHERE contract_id='<legacy_db>' ORDER BY created_at DESC LIMIT 20;"

# Auditoria (tabelas legadas, push-mode)
docker exec conciliador-migrator-postgres psql -U conciliador -d conciliador -c \
  "SELECT entity, status, total_inserted, total_failed, processing_time_ms FROM migration_audit_logs ORDER BY started_at DESC LIMIT 10;"
```

---

## Pull-mode (fluxo ativo)

```bash
# 1. Aplicar migrations (cria migration_jobs)
docker exec conciliador-migrator php bin/hyperf.php migrate

# 2. Disparar job
curl -X POST http://localhost:9501/api/v1/migration/database \
  -H "X-Api-Key: $API_KEY" \
  -d '{"legacy_db": "cliente_x"}'
# → 202 com { job_id, status, status_url }

# 3. Polling
curl http://localhost:9501/api/v1/migration/job/<job_id> \
  -H "X-Api-Key: $API_KEY"

# 4. Listar jobs (filtro opcional)
curl "http://localhost:9501/api/v1/migration/jobs?legacy_db=cliente_x" \
  -H "X-Api-Key: $API_KEY"
```

---

## E2E smoke tests

```bash
# Push-mode (legado, dados sintéticos)
bash test/e2e/smoke-test.sh
bash test/e2e/smoke-test.sh --clean

# Pull-mode (lê banco legado real, transforma, migra)
LEGACY_DB=teste bash test/e2e/smoke-pull-mode.sh
MIGRATION_ENCRYPTED_API_KEY=v1... bash test/e2e/smoke-pull-mode.sh
```

---

## Adicionar uma nova entidade ao pull-mode

1. Criar classe em `app/PullMode/Source` estendendo `AbstractLegacySource`.
2. Definir `entity()`, `targetTable()` e `sql()`.
3. Usar aliases no SQL com nomes finais esperados no destino.
4. Expor IDs legados como `legacy_id` e FKs como `legacy_<nome>_id`.
5. Definir `fkMap()` apontando cada FK legada para a entidade do mapping.
6. Usar keyset pagination (`:last_id`, `:limit`) para tabelas grandes.
7. Sobrescrever `transformRow()` apenas para regras que não cabem no SQL.
8. Sobrescrever `hasContractId()` para `false` quando o destino não tiver `contract_id`.
9. Usar `uuid7` para IDs gerados pela aplicação (padrão do pipeline).
10. Adicionar a Source em `EntityMetadataRegistry::sources()` **depois** de todas as dependências de FK.

**Não paralelize entidades no orchestrator**: a ordem do registry é parte do contrato de consistência por FK.
