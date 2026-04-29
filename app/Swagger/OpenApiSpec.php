<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.1.0',
    title: 'Conciliador Migrator API',
    description: <<<'DESC'
API de migração de dados do sistema legado para o Conciliador Web.
Segue o **Richardson Maturity Level 2** — recursos, verbos HTTP corretos e status codes semânticos.
Erros retornam **RFC 7807 Problem Details** (`Content-Type: application/problem+json`).

## Visão Geral
- Suporta inserção em lote (batch) com mapeamento automático de IDs legados para UUIDs
- **Sync**: Inserção direta via `DB::transaction()` — ideal para dados de referência (< 10K registros)
- **Async**: Processamento paralelo via coroutines Swoole — ideal para alto volume (1M+ registros)

## Status Codes

### Sucesso
| Código | Semântica | Quando ocorre |
|--------|-----------|---------------|
| `200 OK` | Replay idempotente | Todos os registros já existiam (batch duplicado) |
| `201 Created` | Inserção total | Todos os registros inseridos sem falhas |
| `202 Accepted` | Async aceito | Batch assíncrono aceito; use `status_url` / header `Location` para acompanhar |
| `207 Multi-Status` | Parcial | Alguns registros inseridos, outros falharam |

### Erro (corpo RFC 7807)
| Código | Semântica |
|--------|-----------|
| `401 Unauthorized` | API key ausente ou inválida |
| `403 Forbidden` | Acesso negado |
| `404 Not Found` | Recurso (ex: batch) não encontrado |
| `413 Content Too Large` | Batch excede o limite máximo |
| `422 Unprocessable Entity` | Batch vazio ou todos os registros falharam na validação |
| `429 Too Many Requests` | Rate limit excedido |
| `500 Internal Server Error` | Erro inesperado no servidor |

## Envelope de Erro (RFC 7807)
```json
{
  "type": "about:blank",
  "title": "Unprocessable Entity",
  "status": 422,
  "detail": "The 'batch' field is required and must not be empty.",
  "errors": []
}
```

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

## Resolução de FK Legados
Envie campos `legacy_*_id` no batch e a API resolve automaticamente para UUIDs via tabela `migration_id_mappings`.

## Rate Limits
- Endpoints síncronos: **60 req/min** por contract
- Endpoints assíncronos (bulk): **30 req/min** por contract

## Autenticação
Envie a API key no header `X-Api-Key`.
O valor recebido é comparado diretamente com `MIGRATION_API_KEY` configurada no `.env`.
DESC,
    contact: new OA\Contact(name: 'DevCC Team', email: 'dev@conciliador.com')
)]
#[OA\Server(url: 'http://localhost:9501', description: 'Local Development (Docker)')]
#[OA\Server(url: 'http://migrator.conciliador.local:9501', description: 'Staging')]
#[OA\SecurityScheme(
    securityScheme: 'apiKeyAuth',
    type: 'apiKey',
    name: 'X-Api-Key',
    in: 'header',
    description: 'API key simples enviada no header X-Api-Key. O valor é comparado com MIGRATION_API_KEY no .env.'
)]
#[OA\Tag(name: 'Health', description: 'Health check do serviço')]
#[OA\Tag(name: 'Auth', description: 'Autenticação por API key')]
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
