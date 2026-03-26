<?php

declare(strict_types=1);

namespace App\Controller\Migration;

use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Service\IdMappingService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Swagger\Annotation\HyperfServer;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;

/**
 * Pivot table contract_user — sem id próprio, sem timestamps.
 * Não herda AbstractMigrationController pois o padrão de insert difere.
 */
#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class ContractUserMigrationController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

    #[Inject]
    protected IdMappingService $idMappingService;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    private const MAX_BATCH_SIZE = 500;

    #[OA\Post(
        path: '/api/v1/migration/contract-users',
        summary: 'Migrar vínculos usuário × contrato',
        description: 'Insere vínculos na tabela pivot contract_user (síncrono). Fase 2b — obrigatório para acesso dos usuários ao sistema. Tabela sem id próprio e sem timestamps. Max batch: 500. FK legados: legacy_user_id, legacy_contract_id, legacy_role_id.',
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
                            'legacy_user_id'     => 'USR-001',
                            'legacy_contract_id' => 'LEG-001',
                            'legacy_role_id'     => 'ROLE-ADMIN',
                            'contract_admin'     => true,
                        ],
                        [
                            'legacy_user_id'     => 'USR-002',
                            'legacy_contract_id' => 'LEG-001',
                            'legacy_role_id'     => 'ROLE-USER',
                            'contract_admin'     => false,
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
                        'inserted'    => 2,
                        'failed'      => 0,
                        'errors'      => [],
                        'id_mappings' => [],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'contract-users')]
    public function migrate(): array
    {
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($batch) > self::MAX_BATCH_SIZE) {
            return ['error' => 'Batch size exceeds maximum of ' . self::MAX_BATCH_SIZE, 'code' => 422];
        }

        $contractId = $this->request->getAttribute('contract_id', $this->request->header('X-Contract-Id', ''));

        $rules = [
            'user_id'        => 'required|uuid',
            'contract_id'    => 'required|uuid',
            'role_id'        => 'required|uuid',
            'contract_admin' => 'nullable|boolean',
        ];

        $validationErrors = [];
        $records = [];
        foreach ($batch as $index => $record) {
            // 1. Resolver legacy FKs antes da validação
            if (! empty($record['legacy_user_id'])) {
                $record['user_id'] = $this->idMappingService->resolve('users', $record['legacy_user_id'], $contractId) ?? $record['user_id'] ?? null;
                unset($record['legacy_user_id']);
            }

            if (! empty($record['legacy_contract_id'])) {
                $record['contract_id'] = $this->idMappingService->resolve('contracts', $record['legacy_contract_id'], $contractId) ?? $record['contract_id'] ?? null;
                unset($record['legacy_contract_id']);
            }

            if (! empty($record['legacy_role_id'])) {
                $record['role_id'] = $this->idMappingService->resolve('roles', $record['legacy_role_id'], $contractId) ?? $record['role_id'] ?? null;
                unset($record['legacy_role_id']);
            }

            // 2. Limpar campos que não pertencem à pivot
            unset($record['legacy_id'], $record['id'], $record['created_at'], $record['updated_at']);

            // 3. Validar UUIDs resolvidos
            $validator = $this->validatorFactory->make($record, $rules);
            if ($validator->fails()) {
                $validationErrors[] = [
                    'index'             => $index,
                    'legacy_id'         => null,
                    'validation_errors' => $validator->errors()->toArray(),
                ];
                continue;
            }

            $records[] = $record;
        }

        $inserted = 0;
        $failed = 0;
        $errors = [];

        if (! empty($records)) {
            try {
                Db::connection('conciliador_web')->beginTransaction();
                Db::connection('conciliador_web')->table('contract_user')->insert($records);
                Db::connection('conciliador_web')->commit();
                $inserted = \count($records);
            } catch (\Throwable $e) {
                Db::connection('conciliador_web')->rollBack();
                $failed = \count($records);
                $errors[] = ['message' => $e->getMessage()];
            }
        }

        return [
            'inserted'    => $inserted,
            'failed'      => $failed + \count($validationErrors),
            'errors'      => array_merge($validationErrors, $errors),
            'id_mappings' => [],
        ];
    }
}
