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
class ConfrontationMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'confrontations';
    }

    protected function getEntity(): string
    {
        return 'confrontations';
    }

    protected function getMaxBatchSize(): int
    {
        return 50;
    }

    protected function validationRules(): array
    {
        return [
            'description'             => 'required|string|max:255',
            'user_create_id'          => 'required|uuid',
            'user_create'             => 'required|string|max:255',
            'company_name'            => 'nullable|string|max:255',
            'company_cnpj'            => 'nullable|string|max:14',
            'consider_date'           => 'nullable|boolean',
            'consider_debit_credit'   => 'nullable|boolean',
            'consider_document'       => 'nullable|boolean',
            'consider_history'        => 'nullable|boolean',
            'ignore_equals'           => 'nullable|boolean',
            'selected_bank_financial' => 'nullable|string',
            'selected_bank_bank'      => 'nullable|string',
            'layouts'                 => 'nullable|string',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/confrontations',
        summary: 'Migrar confrontações',
        description: 'Insere confrontações em lote (síncrono). Fase 9 — depende de contracts, companies. Max batch: 50. FK legados: legacy_contract_id, legacy_company_id.',
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
    #[PostMapping(path: 'confrontations')]
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
        if (! empty($record['legacy_contract_id'])) {
            $record['contract_id'] = $this->idMappingService->resolve('contracts', $record['legacy_contract_id'], $contractId) ?? $record['contract_id'] ?? null;
            unset($record['legacy_contract_id']);
        }

        if (! empty($record['legacy_company_id'])) {
            $record['company_id'] = $this->idMappingService->resolve('companies', $record['legacy_company_id'], $contractId) ?? $record['company_id'] ?? null;
            unset($record['legacy_company_id']);
        }

        return $record;
    }
}
