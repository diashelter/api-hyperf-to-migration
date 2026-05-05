# CLAUDE.md — conciliador-migrator

API HTTP em **Hyperf 3.1 / Swoole 5** (PHP 8.1+) com PostgreSQL. Pull-mode: recebe um `legacy_db`, cria um job em fila Redis, e um worker lê o legado e insere no `conciliador_web`. Idempotente via `migration_id_mappings`.

A documentação canônica vive em `docs/ai/`. Os arquivos abaixo são importados sempre; os demais devem ser lidos sob demanda quando o assunto aparecer.

## Sempre carregados

@docs/ai/overview.md
@docs/ai/database.md
@docs/ai/security.md

## Ler sob demanda

- `docs/ai/architecture.md` — desenho do sistema, fluxo de job, pipeline de entidade, sources, idempotência.
- `docs/ai/styleguide.md` — convenções de código PHP, naming, DI, formato de resposta.
- `docs/ai/controllers.md` — padrão `AbstractMigrationController` (push-mode legado).
- `docs/ai/testing.md` — PHPUnit, Mockery, helpers de `UnitTestCase`.
- `docs/ai/workflows.md` — comandos Docker, smoke tests, pull-mode end-to-end, adicionar nova entidade.

## Regra de ouro

Toda regra mora em **exatamente um** arquivo dentro de `docs/ai/`. Este `CLAUDE.md` e os arquivos em `.cursor/rules/*.mdc` são entry points finos — referenciam, nunca duplicam.
