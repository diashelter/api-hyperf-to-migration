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

use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class MigrationStatusController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

    #[Inject]
    protected MigrationBatchService $batchService;

    #[Inject]
    protected IdMappingService $idMappingService;

    #[OA\Get(
        path: '/api/v1/migration/status/{batchId}',
        summary: 'Consultar status de um batch',
        description: 'Retorna o status detalhado de um batch de migração assíncrona, incluindo contadores de inserção/falha e erros.',
        tags: ['Status & Control'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/X-Contract-Id'),
            new OA\Parameter(
                name: 'batchId',
                in: 'path',
                required: true,
                description: 'UUID do batch de migração',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status do batch', content: new OA\JsonContent(ref: '#/components/schemas/MigrationBatchStatus')),
            new OA\Response(response: 404, description: 'Batch não encontrado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    #[GetMapping(path: 'status/{batchId}')]
    public function show(string $batchId): array
    {
        $status = $this->batchService->getStatus($batchId);

        if (! $status) {
            return ['error' => 'Batch not found', 'code' => 404];
        }

        return $status;
    }

    #[OA\Get(
        path: '/api/v1/migration/status',
        summary: 'Listar batches de migração',
        description: 'Lista todos os batches de migração do contrato atual, ordenados por data de criação.',
        tags: ['Status & Control'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de batches',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/MigrationBatchStatus')
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    #[GetMapping(path: 'status')]
    public function index(): array
    {
        $contractId = $this->request->getAttribute('contract_id', $this->request->header('X-Contract-Id', ''));

        if (empty($contractId)) {
            return ['error' => 'Missing X-Contract-Id header', 'code' => 422];
        }

        return $this->batchService->listByContract($contractId);
    }

    #[OA\Post(
        path: '/api/v1/migration/id-mapping',
        summary: 'Consultar mapeamento de IDs legados',
        description: 'Resolve IDs do sistema legado para UUIDs do novo sistema. Útil para verificar mapeamentos após migração ou para resolver dependências manualmente.',
        tags: ['Status & Control'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/IdMappingRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Mapeamentos encontrados', content: new OA\JsonContent(ref: '#/components/schemas/IdMappingResponse')),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Parâmetros obrigatórios ausentes', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    #[PostMapping(path: 'id-mapping')]
    public function idMapping(): array
    {
        $entity = $this->request->input('entity', '');
        $legacyIds = $this->request->input('legacy_ids', []);
        $contractId = $this->request->getAttribute('contract_id', $this->request->header('X-Contract-Id', ''));

        if (empty($entity) || empty($legacyIds)) {
            return ['error' => 'entity and legacy_ids are required', 'code' => 422];
        }

        $mappings = $this->idMappingService->resolveMany($entity, $legacyIds, $contractId);

        return ['mappings' => $mappings];
    }
}
