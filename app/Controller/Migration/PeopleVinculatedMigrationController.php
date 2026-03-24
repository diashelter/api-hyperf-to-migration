<?php

declare(strict_types=1);

namespace App\Controller\Migration;

use App\Controller\AbstractMigrationController;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\PostMapping;
use OpenApi\Attributes as OA;

#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
class PeopleVinculatedMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'people_vinculated';
    }

    protected function getEntity(): string
    {
        return 'people_vinculated';
    }

    protected function getMaxBatchSize(): int
    {
        return 1000;
    }

    protected function validationRules(): array
    {
        return [
            'debit_account'   => 'nullable|string|max:10',
            'credit_account'  => 'nullable|string|max:10',
            'participant'     => 'nullable|string|max:100',
            'vinculated_name' => 'nullable|string|max:150',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/people-vinculated',
        summary: 'Migrar vínculos de participantes',
        description: 'Insere vínculos people_vinculated em lote (síncrono). Fase 6b — depende de peoples, companies e/ou rules_sharings. CONSTRAINT: exatamente um de company_id OU rules_sharing_id deve ser preenchido. Max batch: 1000. FK legados: legacy_people_id, legacy_company_id, legacy_rules_sharing_id.',
        tags: ['Migration - Sync'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/MigrationBatchRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Migração concluída', content: new OA\JsonContent(ref: '#/components/schemas/SyncMigrationResponse')),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'people-vinculated')]
    public function migrate(): array
    {
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($batch) > $this->getMaxBatchSize()) {
            return ['error' => "Batch size exceeds maximum of {$this->getMaxBatchSize()}", 'code' => 422];
        }

        return $this->syncMigrate();
    }

    protected function resolveForeignKeys(array $record, string $contractId): array
    {
        if (! empty($record['legacy_people_id'])) {
            $record['people_id'] = $this->idMappingService->resolve('peoples', $record['legacy_people_id'], $contractId) ?? $record['people_id'] ?? null;
            unset($record['legacy_people_id']);
        }

        if (! empty($record['legacy_company_id'])) {
            $record['company_id'] = $this->idMappingService->resolve('companies', $record['legacy_company_id'], $contractId) ?? $record['company_id'] ?? null;
            unset($record['legacy_company_id']);
        }

        if (! empty($record['legacy_rules_sharing_id'])) {
            $record['rules_sharing_id'] = $this->idMappingService->resolve('rules_sharings', $record['legacy_rules_sharing_id'], $contractId) ?? $record['rules_sharing_id'] ?? null;
            unset($record['legacy_rules_sharing_id']);
        }

        return $record;
    }
}
