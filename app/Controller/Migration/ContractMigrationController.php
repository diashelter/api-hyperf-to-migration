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

namespace App\Controller\Migration;

use App\Controller\AbstractMigrationController;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Service\LookupCacheService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class ContractMigrationController extends AbstractMigrationController
{
    #[Inject]
    protected LookupCacheService $lookupCacheService;

    #[PostMapping(path: 'contracts')]
    public function migrate(): PsrResponseInterface
    {
        return $this->syncMigrate();
    }

    protected function getTable(): string
    {
        return 'contracts';
    }

    protected function getEntity(): string
    {
        return 'contracts';
    }

    protected function getMaxBatchSize(): int
    {
        return 100;
    }

    protected function getConnection(): string
    {
        return 'conciliador_web';
    }

    protected function validationRules(): array
    {
        return [
            'cpf_cnpj' => 'required|string|size:14',
            'corporate_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:15',
            'contractor_type' => 'required|in:individual,company',
            'company_count' => 'required|integer|min:1',
            'user_count' => 'nullable|integer|min:1',
            'street' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:50',
            'neighborhood' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'complement' => 'nullable|string',
            'state' => 'nullable|string|size:2',
            'zipcode' => 'nullable|string|max:10',
            'activity_branch' => 'nullable|string',
            'is_approval' => 'nullable|boolean',
            'legacy_database_id' => 'nullable|string|max:100',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/contracts',
        summary: 'Migrar contratos',
        description: <<<'DESC'
        Insere contratos em lote (síncrono). Fase 1a da migração — sem dependências de FK. Max batch: 100.

        **Status do contrato:**
        Envie `legacy_status_contract` com o label exato cadastrado em `conciliador_web.status` (ex: `ATIVO`, `CANCELADO`, `SUSPENSO`).
        O valor é resolvido via lookup_cache (entidade `status`). Se omitido, `status_contract` ficará nulo.

        **Pré-requisito:** `php bin/hyperf.php migration:seed-lookups status`

        **Normalização automática:** todos os campos string (exceto `email`, `contractor_type`, `password` e campos `*_id`) são convertidos para maiúsculas.
        DESC,
        tags: ['Migration - Sync'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/MigrationBatchRequest',
                example: [
                    'batch' => [
                        [
                            'legacy_id' => 'CONTRACT-001',
                            'cpf_cnpj' => '12345678000195',
                            'corporate_name' => 'Empresa Exemplo Ltda',
                            'name' => 'Empresa Exemplo',
                            'email' => 'contato@empresa.com',
                            'phone' => '11987654321',
                            'contractor_type' => 'company',
                            'company_count' => 5,
                            'user_count' => 10,
                            'street' => 'Rua das Flores',
                            'number' => '123',
                            'city' => 'São Paulo',
                            'state' => 'SP',
                            'zipcode' => '01310100',
                            'legacy_status_contract' => 'ATIVO',
                        ],
                    ],
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Replay idempotente (todos os registros já existiam)', content: new OA\JsonContent(ref: '#/components/schemas/SyncMigrationResponse')),
            new OA\Response(response: 201, description: 'Migração concluída com sucesso', content: new OA\JsonContent(ref: '#/components/schemas/SyncMigrationResponse')),
            new OA\Response(response: 207, description: 'Migração parcial — alguns registros falharam', content: new OA\JsonContent(ref: '#/components/schemas/SyncMigrationResponse')),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 413, description: 'Batch excede o limite máximo', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou todos os registros falharam', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
            new OA\Response(response: 500, description: 'Erro interno do servidor', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
        ]
    )]
    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_status_contract'])) {
            $record['status_contract'] = $this->lookupCacheService->resolve('status', $record['legacy_status_contract']) ?? $record['status_contract'] ?? null;
            unset($record['legacy_status_contract']);
        }

        return $record;
    }
}
