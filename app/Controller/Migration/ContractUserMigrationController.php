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

use App\Exception\BatchTooLargeException;
use App\Exception\EmptyBatchException;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Service\IdMappingService;
use App\Service\LookupCacheService;
use App\Service\MigrationAuditService;
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
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Pivot table contract_user — sem id próprio, sem timestamps.
 * Não herda AbstractMigrationController pois o padrão de insert difere.
 */
#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class ContractUserMigrationController
{
    private const MAX_BATCH_SIZE = 500;

    private const ENTITY = 'contract_users';

    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

    #[Inject]
    protected IdMappingService $idMappingService;

    #[Inject]
    protected LookupCacheService $lookupCacheService;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    #[Inject]
    protected MigrationAuditService $auditService;

    #[OA\Post(
        path: '/api/v1/migration/contract-users',
        summary: 'Migrar vínculos usuário × contrato',
        description: <<<'DESC'
        Insere vínculos na tabela pivot `contract_user` (síncrono). Fase 2b — obrigatório para acesso dos usuários ao sistema. Tabela sem id próprio e sem timestamps. Max batch: 500.

        **Resolução de FKs legadas:**
        - `legacy_user_id` → resolve via `migration_id_mappings` (entidade `users`, escopo `contract_id` do token)
        - `legacy_contract_id` → resolve via `migration_id_mappings` (entidade `contracts`)
        - `legacy_role_id` → resolve via `lookup_cache` (entidade `roles`, busca pelo **label**): `owner` | `user` | `admin` | `support`

        **Efeito colateral:** o trigger `seed_permission` no `contract_user` concede automaticamente todas as 52 permissões ao par usuário × contrato. Use o endpoint `/user-permissions` (Fase 2c) para revogar as que o usuário não deve ter.

        **Pré-requisito:** `php bin/hyperf.php migration:seed-lookups roles`
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
                            'legacy_user_id' => 'USR-001',
                            'legacy_contract_id' => 'CONTRACT-001',
                            'legacy_role_id' => 'owner',
                            'contract_admin' => true,
                        ],
                        [
                            'legacy_user_id' => 'USR-002',
                            'legacy_contract_id' => 'CONTRACT-001',
                            'legacy_role_id' => 'user',
                            'contract_admin' => false,
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
    #[PostMapping(path: 'contract-users')]
    public function migrate(): PsrResponseInterface
    {
        $requestId = Uuid::uuid4()->toString();
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            throw new EmptyBatchException();
        }

        if (\count($batch) > self::MAX_BATCH_SIZE) {
            throw new BatchTooLargeException(self::MAX_BATCH_SIZE);
        }

        $contractId = $this->request->getAttribute('contract_id', $this->request->header('X-Contract-Id', ''));

        $this->auditService->open(
            $requestId,
            $contractId,
            self::ENTITY,
            $batch,
            $this->getRequestIpAddress(),
            $this->getRequestUserAgent()
        );

        $rules = [
            'user_id' => 'required|uuid',
            'contract_id' => 'required|uuid',
            'role_id' => 'required|uuid',
            'contract_admin' => 'nullable|boolean',
        ];

        $validationErrors = [];
        $validationRecordLogs = [];
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
                $record['role_id'] = $this->lookupCacheService->resolve('roles', $record['legacy_role_id']) ?? $record['role_id'] ?? null;
                unset($record['legacy_role_id']);
            }

            // 2. Limpar campos que não pertencem à pivot
            unset($record['legacy_id'], $record['id'], $record['created_at'], $record['updated_at']);

            // 3. Validar UUIDs resolvidos
            $validator = $this->validatorFactory->make($record, $rules);
            if ($validator->fails()) {
                $error = [
                    'index' => $index,
                    'legacy_id' => null,
                    'validation_errors' => $validator->errors()->toArray(),
                ];
                $validationErrors[] = $error;
                $validationRecordLogs[] = [
                    'legacy_id' => null,
                    'new_id' => null,
                    'status' => 'validation_error',
                    'error_message' => json_encode(
                        $error['validation_errors'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ];
                continue;
            }

            $records[] = $record;
        }

        $inserted = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $recordLogs = $validationRecordLogs;

        if (! empty($records)) {
            try {
                $connection = Db::connection('conciliador_web');
                $connection->beginTransaction();
                $inserted = $connection->table('contract_user')->insertOrIgnore($records);
                $connection->commit();
                $skipped = max(0, \count($records) - $inserted);

                foreach ($records as $index => $record) {
                    $recordLogs[] = [
                        'legacy_id' => null,
                        'new_id' => null,
                        'status' => $index < $inserted ? 'inserted' : 'skipped_duplicate',
                        'error_message' => null,
                    ];
                }
            } catch (Throwable $e) {
                Db::connection('conciliador_web')->rollBack();
                $failed = \count($records);
                $errors[] = ['message' => $e->getMessage()];

                foreach ($records as $record) {
                    $recordLogs[] = [
                        'legacy_id' => null,
                        'new_id' => null,
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ];
                }
            }
        }

        $responsePayload = [
            'inserted' => $inserted,
            'skipped' => $skipped,
            'failed' => $failed + \count($validationErrors),
            'errors' => array_merge($validationErrors, $errors),
            'id_mappings' => [],
        ];

        $this->auditService->close($requestId, $responsePayload);

        if ($this->auditService->shouldLogRecords(self::ENTITY)) {
            $this->auditService->logRecords($requestId, $contractId, self::ENTITY, $recordLogs);
        }

        return $this->response->json($responsePayload)->withStatus($this->resolveSyncStatus($responsePayload));
    }

    private function resolveSyncStatus(array $payload): int
    {
        $inserted = (int) ($payload['inserted'] ?? 0);
        $failed = (int) ($payload['failed'] ?? 0);

        if ($inserted > 0 && $failed === 0) {
            return 201;
        }

        if ($inserted > 0 && $failed > 0) {
            return 207;
        }

        if ($inserted === 0 && $failed > 0) {
            return 422;
        }

        return 200;
    }

    private function getRequestIpAddress(): ?string
    {
        $serverParams = $this->request->getServerParams();

        return isset($serverParams['remote_addr']) ? (string) $serverParams['remote_addr'] : null;
    }

    private function getRequestUserAgent(): ?string
    {
        return $this->request->header('user-agent', '');
    }
}
