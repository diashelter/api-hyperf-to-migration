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
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class UserCompanyRestrictionMigrationController extends AbstractMigrationController
{
    #[OA\Post(
        path: '/api/v1/migration/user-company-restrictions',
        summary: 'Migrar restrições de usuário por empresa',
        description: <<<'DESC'
        Insere registros de restrição de acesso de usuários em empresas na tabela `user_company_restrictions` em lote (síncrono). Max batch: 500.

        Cada registro indica que um usuário está **bloqueado** dentro de uma empresa específica no contrato.

        **Resolução de FKs legadas:**
        - `legacy_user_id` → resolve via `migration_id_mappings` (entidade `users`)
        - `legacy_company_id` → resolve via `migration_id_mappings` (entidade `companies`)
        - `contract_id` → derivado do token JWT (X-Contract-Id), resolvido via `migration_id_mappings` (entidade `contracts`)

        **Pré-requisitos:** migrações de `users` e `companies` concluídas (entidades devem existir em `migration_id_mappings`)
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
                            'legacy_id' => 'UCR-001',
                            'legacy_user_id' => 'USR-123',
                            'legacy_company_id' => 'COMP-456',
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
    #[PostMapping(path: 'user-company-restrictions')]
    public function migrate(): PsrResponseInterface
    {
        return $this->syncMigrate();
    }

    protected function getTable(): string
    {
        return 'user_company_restrictions';
    }

    protected function getEntity(): string
    {
        return 'user_company_restrictions';
    }

    protected function getMaxBatchSize(): int
    {
        return 500;
    }

    protected function getConnection(): string
    {
        return 'conciliador_web';
    }

    protected function validationRules(): array
    {
        return [
            'legacy_user_id' => 'required|string|max:255',
            'legacy_company_id' => 'required|string|max:255',
        ];
    }

    protected function getForeignKeyMap(): array
    {
        return [
            'legacy_user_id' => 'users',
            'legacy_company_id' => 'companies',
        ];
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_user_id'])) {
            $record['user_id'] = $this->idMappingService->resolve('users', $record['legacy_user_id'], $contractId);
            unset($record['legacy_user_id']);
        }

        if (! empty($record['legacy_company_id'])) {
            $record['company_id'] = $this->idMappingService->resolve('companies', $record['legacy_company_id'], $contractId);
            unset($record['legacy_company_id']);
        }

        return $this->resolveContractIdFK($record, $contractId);
    }
}
