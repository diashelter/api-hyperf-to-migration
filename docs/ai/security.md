# Security

## Autenticação

- Header `X-Api-Key` é obrigatório nos endpoints de migração.
- `ApiTokenMiddleware` compara o valor recebido diretamente com `MIGRATION_API_KEY` do `.env`.
- `X-Contract-Id` **não** é mais necessário no pull-mode atual — `legacy_db` substitui esse papel como namespace interno (vai em `migration_jobs.contract_id` e `migration_id_mappings.contract_id`).
- O UUID real usado como `contract_id` no destino vem do registro inserido em `contracts`, mapeado por `entity=contracts` e `legacy_id=<legacy_db>`.

### Suporte criptografado (não conectado)

`ApiKeyService` tem suporte a payload AES-256-GCM no formato `v1.<iv>.<tag>.<ciphertext>` e a `MIGRATION_API_KEYS` (lista). `ApiTokenMiddleware` **não** usa esse serviço hoje, então esse formato ainda não vale no fluxo real.

---

## Rate limiting

`RateLimitMiddleware` usa Redis com chave `migration_rate:{legacy_db}:standard`. Limites controlados por:

```env
MIGRATION_RATE_LIMIT=60          # req/min padrão
MIGRATION_BULK_RATE_LIMIT=30     # req/min endpoints de alto volume
```

---

## Auditoria (legado)

`MigrationAuditService` e tabelas `migration_audit_logs` / `migration_record_logs` existem mas **não são chamados pelo pull-mode atual**. Sobraram do desenho push-mode. Variáveis correspondentes:

```env
MIGRATION_AUDIT_ENABLED=true
MIGRATION_LOG_RECORDS=true
MIGRATION_SKIP_LOG_ENTITIES=import_records,confrontation_records,rules
```

---

## Tratamento de exceções

- `AppExceptionHandler` renderiza `ApiException` como RFC 7807 (`application/problem+json`).
- Exceções não mapeadas viram HTTP 500 genérico.
- `DiscordNotificationService::notifyException()` é chamado pelo handler global quando notificações estão habilitadas.

---

## Regras `Do Not`

- **Nunca** use `Db::table(...)` sem especificar connection — gera dados órfãos no banco errado.
- **Nunca** chame `insertService->insertSync/insertBatch` sem `filterDuplicates()` antes — callers fora de `AbstractMigrationController` precisam implementar deduplicação própria.
- **Nunca** adicione record-level logging para `import_records`, `confrontation_records` ou `rules` — essas tabelas têm milhões de linhas e estouram `migration_record_logs`.
- **Nunca** rode `storeBatch()` antes do insert ter sucesso — cria FKs quebradas.
- **Nunca** use injeção por construtor em controllers — apenas `#[Inject]`.
- **Nunca** commitar `.env`, chaves de API ou credenciais.
- **Audit failures must never block migration**: capture exceções de auditoria com `error_log(...)` e siga.
