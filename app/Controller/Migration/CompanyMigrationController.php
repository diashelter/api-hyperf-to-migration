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
class CompanyMigrationController extends AbstractMigrationController
{
    protected function getTable(): string
    {
        return 'companies';
    }

    protected function getEntity(): string
    {
        return 'companies';
    }

    protected function getMaxBatchSize(): int
    {
        return 100;
    }

    protected function validationRules(): array
    {
        return [
            'code'           => 'required|string',
            'cpf_cnpj'       => 'required|string|size:14',
            'corporate_name' => 'nullable|string|max:255',
            'external_code'  => 'nullable|string|max:20',
            'tax_regime'     => 'required|in:Lucro Real,Lucro Presumido,Simples Nacional,Outros',
            'street'         => 'nullable|string|max:255',
            'number'         => 'nullable|string|max:50',
            'neighborhood'   => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
            'complement'     => 'nullable|string',
            'state'          => 'nullable|string|size:2',
            'zipcode'        => 'nullable|string|max:10',
            'state_registration'          => 'nullable|string|max:20',
            'city_registration'           => 'nullable|string|max:20',
            'phone'          => 'nullable|string|max:15',
            'phone_cell'     => 'nullable|string|max:15',
            'email'          => 'nullable|email|max:100',
            'is_active'      => 'nullable|boolean',
            'observation'    => 'nullable|string',
            'use_participant'             => 'nullable|boolean',
            'use_cost_center'             => 'nullable|boolean',
            'use_auto_register_of_people' => 'nullable|boolean',
        ];
    }

    #[OA\Post(
        path: '/api/v1/migration/companies',
        summary: 'Migrar empresas',
        description: 'Insere empresas em lote (síncrono). Fase 4 da migração — depende de contracts, plans, rules_sharings. Max batch: 100. FK legados aceitos: legacy_contract_id, legacy_plan_id, legacy_rules_sharing_id.',
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
                            'legacy_id'             => 'EMP-001',
                            'code'                  => 'EMP001',
                            'cpf_cnpj'              => '98765432000188',
                            'corporate_name'        => 'Empresa Filial Ltda',
                            'tax_regime'            => 'Lucro Presumido',
                            'city'                  => 'Campinas',
                            'state'                 => 'SP',
                            'email'                 => 'filial@empresa.com',
                            'is_active'             => true,
                            'legacy_contract_id'    => 'LEG-001',
                            'legacy_plan_id'        => 'PLN-001',
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
                        'id_mappings' => ['EMP-001' => 'eee55555-0000-0000-0000-000000000001'],
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'companies')]
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

        if (! empty($record['legacy_plan_id'])) {
            $record['plan_id'] = $this->idMappingService->resolve('plans', $record['legacy_plan_id'], $contractId) ?? $record['plan_id'] ?? null;
            unset($record['legacy_plan_id']);
        }

        if (! empty($record['legacy_rules_sharing_id'])) {
            $record['rules_sharing_id'] = $this->idMappingService->resolve('rules_sharings', $record['legacy_rules_sharing_id'], $contractId) ?? $record['rules_sharing_id'] ?? null;
            unset($record['legacy_rules_sharing_id']);
        }

        // Mutator do Model Company: city é convertido para UPPERCASE
        // (não aplicado automaticamente em DB::table()->insert())
        if (isset($record['city']) && $record['city'] !== null) {
            $record['city'] = mb_strtoupper((string) $record['city']);
        }

        return $record;
    }
}
