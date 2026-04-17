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
class ImportSessionMigrationController extends AbstractMigrationController
{
    #[OA\Post(
        path: '/api/v1/migration/import-sessions',
        summary: 'Migrar sessões de importação',
        description: 'Insere sessões de importação em lote (síncrono). Fase 8 — depende de imports, layouts. Max batch: 200. FK legados: legacy_import_id, legacy_layout_id.',
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
                            'legacy_id' => 'IS-001',
                            'original_file_name' => 'extrato_jan2024.csv',
                            'file_name' => 'extrato_jan2024_migrado.csv',
                            'date_to_create' => '2024-01-15',
                            'size' => 204800,
                            'legacy_import_id' => 'IMP-001',
                            'legacy_layout_id' => 'LAY-001',
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
    #[PostMapping(path: 'import-sessions')]
    public function migrate(): PsrResponseInterface
    {
        return $this->syncMigrate();
    }

    protected function getTable(): string
    {
        return 'import_sessions';
    }

    protected function getEntity(): string
    {
        return 'import_sessions';
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
            'legacy_layout_id' => 'required|integer',
            'legacy_import_id' => 'nullable|string',
            'file_name' => 'nullable|string',
            'date_to_create' => 'nullable|string',
            'size' => 'nullable|integer',
        ];
    }

    protected function getForeignKeyMap(): array
    {
        return [
            'legacy_import_id' => 'imports',
            'legacy_layout_id' => 'layouts',
        ];
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_import_id'])) {
            $record['import_id'] = $this->idMappingService->resolve('imports', $record['legacy_import_id'], $contractId) ?? $record['import_id'] ?? null;
            unset($record['legacy_import_id']);
        }

        if (! empty($record['legacy_layout_id'])) {
            $record['layout_id'] = $this->idMappingService->resolve('layouts', $record['legacy_layout_id'], $contractId) ?? $record['layout_id'] ?? null;
            unset($record['legacy_layout_id']);
        }
        $record['file_name'] = '';
        return $record;
    }
}
