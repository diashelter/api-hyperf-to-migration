<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Conciliador Migrator API',
    description: <<<'DESC'
API de migração de dados do sistema legado para o Conciliador Web.

## Visão Geral
- Suporta inserção em lote (batch) com mapeamento automático de IDs legados para UUIDs
- **Sync**: Inserção direta via `DB::transaction()` — ideal para dados de referência (< 10K registros)
- **Async**: Processamento paralelo via coroutines Swoole — ideal para alto volume (1M+ registros)

## Ordem de Migração (respeitar dependências FK)
1. `contracts` — sem dependências (raiz do tenant)
2. `users` → `contract_users` — users sem dependência; contract_users depende de contracts, users, roles
3. `rules_sharings` — depende de contracts (opcional)
4. `plans` → `plan_items` — depende de contracts
5. `layouts` — depende de contracts
6. `companies` — depende de contracts, plans?, rules_sharings?
7. `company_layouts` — **CRÍTICO**: pivot entre companies e layouts; obrigatório antes de imports
8. `peoples` → `people_vinculated` — peoples depende de contracts; people_vinculated depende de peoples, companies/rules_sharings
9. `imports` → `import_sessions` → `import_records` (async) — imports depende de companies, company_layout, contracts; usar `finalize: true` no último batch de import_records
10. `rules` (async) — depende de companies, layouts, contracts
11. `confrontations` → `confrontation_records` (async) — confrontations depende de contracts, companies
12. `exports` — depende de contracts, imports, users, companies

## Resolução de FK Legados
Envie campos `legacy_*_id` no batch e a API resolve automaticamente para UUIDs via tabela `migration_id_mappings`.

## Rate Limits
- Endpoints síncronos: **60 req/min** por contract
- Endpoints assíncronos (bulk): **30 req/min** por contract

## Autenticação
Obtenha um token JWT via `POST /api/v1/token` e envie no header `Authorization: Bearer {token}`.
DESC,
    contact: new OA\Contact(name: 'DevCC Team', email: 'dev@conciliador.com')
)]
#[OA\Server(url: 'http://localhost:9501', description: 'Local Development (Docker)')]
#[OA\Server(url: 'http://migrator.conciliador.local:9501', description: 'Staging')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'JWT token obtido via POST /api/v1/token. Envie também o header X-Contract-Id com o UUID do contrato.'
)]
#[OA\Tag(name: 'Health', description: 'Health check do serviço')]
#[OA\Tag(name: 'Auth', description: 'Autenticação e geração de token JWT')]
#[OA\Tag(name: 'Migration - Sync', description: 'Endpoints de migração síncrona (dados de referência, baixo volume). Inserção direta via DB::transaction().')]
#[OA\Tag(name: 'Migration - Async', description: 'Endpoints de migração assíncrona (alto volume, 1M+). Processamento paralelo via coroutines Swoole com tracking de batch.')]
#[OA\Tag(name: 'Status & Control', description: 'Consulta de status de batches assíncronos e mapeamento de IDs legados → UUIDs')]
// Health check endpoints (closures in routes.php — documented here)
#[OA\Get(
    path: '/',
    summary: 'Service info',
    description: 'Retorna informações básicas do serviço (nome, status, versão).',
    tags: ['Health'],
    responses: [
        new OA\Response(response: 200, description: 'Serviço ativo', content: new OA\JsonContent(ref: '#/components/schemas/HealthResponse')),
    ]
)]
#[OA\Get(
    path: '/health',
    summary: 'Health check',
    description: 'Endpoint de health check para monitoramento e load balancers.',
    tags: ['Health'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Serviço saudável',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'ok'),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time', example: '2026-03-19T10:30:00-03:00'),
                ]
            )
        ),
    ]
)]
class OpenApiSpec
{
}
