<?php

declare(strict_types=1);

namespace App\Controller\Migration;

use App\Controller\AbstractMigrationController;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class UserMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'users';
    }

    protected function getEntity(): string
    {
        return 'users';
    }

    protected function getMaxBatchSize(): int
    {
        return 200;
    }

    protected function getConnection(): string
    {
        return 'conciliador_web';
    }

    protected function validationRules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|max:255',
            'password'    => 'required|string|min:4',
            'is_admin'    => 'nullable|boolean',
            'is_internal' => 'nullable|boolean',
            'status'      => 'nullable|boolean',
        ];
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        return $record;
    }

    #[OA\Post(
        path: '/api/v1/migration/users',
        summary: 'Migrar usuários',
        description: <<<'DESC'
        Insere usuários em lote (síncrono). Fase 1b da migração — sem dependências de FK. Max batch: 200.

        **Processamento automático:**
        - `password`: armazenado com hash bcrypt (nunca em texto plano)
        - `email`: convertido para minúsculas
        - Demais campos string: convertidos para maiúsculas
        - `legacy_id`: usado para idempotência e rastreamento — não persiste na tabela `users`

        **Idempotência:** registros com `legacy_id` já migrado são ignorados (skipped) sem erro.
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
                            'legacy_id'   => 'USR-001',
                            'name'        => 'João Silva',
                            'email'       => 'joao.silva@empresa.com',
                            'password'    => 'senha_legado_123',
                            'is_admin'    => false,
                            'is_internal' => false,
                            'status'      => true,
                        ],
                    ],
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Migração concluída',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/SyncMigrationResponse',
                    example: [
                        'inserted'    => 1,
                        'failed'      => 0,
                        'errors'      => [],
                        'id_mappings' => ['USR-001' => '550e8400-e29b-41d4-a716-446655440001'],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'users')]
    public function migrate(): array
    {
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (count($batch) > $this->getMaxBatchSize()) {
            return ['error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}", 'code' => 422];
        }

        return $this->syncMigrate();
    }
}
