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

use App\Exception\ResourceNotFoundException;
use App\Exception\ValidationFailedException;
use App\Job\RunDatabaseMigrationJob;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Service\LegacyConnectionFactory;
use App\Service\MigrationJobService;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class MigrationJobController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

    #[Inject]
    protected MigrationJobService $jobService;

    #[Inject]
    protected LegacyConnectionFactory $legacyConnectionFactory;

    private DriverInterface $queue;

    public function __construct(DriverFactory $driverFactory)
    {
        $this->queue = $driverFactory->get('default');
    }

    #[OA\Post(
        path: '/api/v1/migration/database',
        summary: 'Disparar job de migração pull-mode',
        description: 'Cria e enfileira um job para migrar dados a partir de um banco legado. O valor de `legacy_db` é usado como nome do database legado a ser conectado.',
        tags: ['Status & Control'],
        security: [['apiKeyAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['legacy_db'],
                properties: [
                    new OA\Property(
                        property: 'legacy_db',
                        type: 'string',
                        example: 'cont_focons',
                        description: 'Nome do database no servidor legado.'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 202,
                description: 'Job enfileirado com sucesso',
                content: new OA\JsonContent(
                    required: ['job_id', 'legacy_db', 'status', 'status_url'],
                    properties: [
                        new OA\Property(property: 'job_id', type: 'string', format: 'uuid', example: '260439b0-9fd0-4b01-a931-ecf2677ed972'),
                        new OA\Property(property: 'legacy_db', type: 'string', example: 'cont_focons'),
                        new OA\Property(property: 'status', type: 'string', example: 'queued'),
                        new OA\Property(property: 'status_url', type: 'string', example: '/api/v1/migration/job/260439b0-9fd0-4b01-a931-ecf2677ed972'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'API key inválida', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 422, description: "Campo 'legacy_db' ausente ou falha na conexão com legado", content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
            new OA\Response(response: 500, description: 'Erro interno do servidor', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
        ]
    )]
    #[PostMapping(path: 'database')]
    public function dispatch(): PsrResponseInterface
    {
        $legacyDb = (string) $this->request->input('legacy_db', '');

        if ($legacyDb === '') {
            throw new ValidationFailedException("The 'legacy_db' field is required.");
        }

        $contractId = $this->getContractId();

        if ($contractId === '') {
            throw new ValidationFailedException("The 'X-Contract-Id' header or API key contract_id payload is required.");
        }

        // Valida whitelist + smoke test antes de aceitar o job. Falha aqui retorna 422.
        $this->legacyConnectionFactory->connect($legacyDb);

        $job = $this->jobService->create($legacyDb, $contractId);
        $jobId = (string) $job->getAttribute('id');

        $this->queue->push(new RunDatabaseMigrationJob($jobId));

        $statusUrl = "/api/v1/migration/job/{$jobId}";

        return $this->response->json([
            'job_id' => $jobId,
            'legacy_db' => $legacyDb,
            'status' => 'queued',
            'status_url' => $statusUrl,
        ])->withStatus(202)->withHeader('Location', $statusUrl);
    }

    #[OA\Get(
        path: '/api/v1/migration/job/{jobId}',
        summary: 'Consultar status de um job de migração',
        description: 'Retorna o status detalhado de um job de migração, incluindo progresso por entidade e resumo de erros.',
        tags: ['Status & Control'],
        security: [['apiKeyAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/X-Contract-Id'),
            new OA\Parameter(
                name: 'jobId',
                in: 'path',
                required: true,
                description: 'UUID do job de migração',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status detalhado do job',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'job_id', type: 'string', format: 'uuid', example: '260439b0-9fd0-4b01-a931-ecf2677ed972'),
                        new OA\Property(property: 'legacy_db', type: 'string', example: 'cont_focons'),
                        new OA\Property(property: 'contract_id', type: 'string', example: '660e8400-e29b-41d4-a716-446655440001'),
                        new OA\Property(property: 'status', type: 'string', example: 'processing'),
                        new OA\Property(property: 'current_entity', type: 'string', nullable: true, example: 'contracts'),
                        new OA\Property(
                            property: 'totals',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'failed', type: 'integer', example: 0),
                                new OA\Property(property: 'skipped', type: 'integer', example: 0),
                                new OA\Property(property: 'inserted', type: 'integer', example: 120),
                            ]
                        ),
                        new OA\Property(
                            property: 'entity_progress',
                            type: 'object',
                            description: 'Mapa por entidade com progresso e erros.'
                        ),
                        new OA\Property(property: 'error_summary', type: 'object', nullable: true),
                        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'API key inválida', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 404, description: 'Job não encontrado', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
            new OA\Response(response: 500, description: 'Erro interno do servidor', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
        ]
    )]
    #[GetMapping(path: 'job/{jobId}')]
    public function show(string $jobId): PsrResponseInterface
    {
        $status = $this->jobService->getStatus($jobId);

        if (! $status) {
            throw new ResourceNotFoundException("Migration job '{$jobId}' not found.");
        }

        return $this->response->json($status);
    }

    #[OA\Get(
        path: '/api/v1/migration/jobs',
        summary: 'Listar jobs de migração do contrato',
        description: 'Lista todos os jobs de migração associados ao contrato atual.',
        tags: ['Status & Control'],
        security: [['apiKeyAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de jobs',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '260439b0-9fd0-4b01-a931-ecf2677ed972'),
                            new OA\Property(property: 'legacy_db', type: 'string', example: 'cont_focons'),
                            new OA\Property(property: 'contract_id', type: 'string', example: '660e8400-e29b-41d4-a716-446655440001'),
                            new OA\Property(property: 'status', type: 'string', example: 'queued'),
                            new OA\Property(property: 'current_entity', type: 'string', nullable: true, example: 'contracts'),
                            new OA\Property(property: 'entity_progress', type: 'object'),
                            new OA\Property(
                                property: 'totals',
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'failed', type: 'integer', example: 0),
                                    new OA\Property(property: 'skipped', type: 'integer', example: 0),
                                    new OA\Property(property: 'inserted', type: 'integer', example: 0),
                                ]
                            ),
                            new OA\Property(property: 'error_summary', type: 'object', nullable: true),
                            new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'created_at', type: 'string', nullable: true, example: '2026-04-29 01:00:16'),
                            new OA\Property(property: 'updated_at', type: 'string', nullable: true, example: '2026-04-29 01:04:28'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'API key inválida', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 422, description: "Header 'X-Contract-Id' ausente", content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
            new OA\Response(response: 500, description: 'Erro interno do servidor', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
        ]
    )]
    #[GetMapping(path: 'jobs')]
    public function index(): PsrResponseInterface
    {
        $contractId = $this->getContractId();

        if ($contractId === '') {
            throw new ValidationFailedException("The 'X-Contract-Id' header or API key contract_id payload is required.");
        }

        return $this->response->json($this->jobService->listByContract($contractId));
    }

    private function getContractId(): string
    {
        return (string) $this->request->getAttribute('contract_id', $this->request->header('X-Contract-Id', ''));
    }
}
