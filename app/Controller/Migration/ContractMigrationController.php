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
class ContractMigrationController extends AbstractMigrationController
{
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
            'cpf_cnpj'           => 'required|string|size:14',
            'corporate_name'     => 'required|string|max:255',
            'name'               => 'required|string|max:255',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:15',
            'contractor_type'    => 'required|in:individual,company',
            'company_count'      => 'required|integer|min:1',
            'user_count'         => 'nullable|integer|min:1',
            'street'             => 'nullable|string|max:255',
            'number'             => 'nullable|string|max:50',
            'neighborhood'       => 'nullable|string|max:100',
            'city'               => 'nullable|string|max:100',
            'complement'         => 'nullable|string',
            'state'              => 'nullable|string|size:2',
            'zipcode'            => 'nullable|string|max:10',
            'activity_branch'    => 'nullable|string',
            'is_approval'        => 'nullable|boolean',
            'legacy_database_id' => 'nullable|string|max:100',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/contracts',
        summary: 'Migrar contratos',
        description: 'Insere contratos em lote (síncrono). Fase 1 da migração — sem dependências de FK. Max batch: 100.',
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
                            'legacy_id'       => 'LEG-001',
                            'cpf_cnpj'        => '12345678000195',
                            'corporate_name'  => 'Empresa Exemplo Ltda',
                            'name'            => 'Empresa Exemplo',
                            'email'           => 'contato@empresa.com',
                            'phone'           => '11987654321',
                            'contractor_type' => 'company',
                            'company_count'   => 5,
                            'user_count'      => 10,
                            'street'          => 'Rua das Flores',
                            'number'          => '123',
                            'city'            => 'São Paulo',
                            'state'           => 'SP',
                            'zipcode'         => '01310100',
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
                        'id_mappings' => ['LEG-001' => '550e8400-e29b-41d4-a716-446655440000'],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'contracts')]
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
