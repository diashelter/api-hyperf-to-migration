<?php

declare(strict_types=1);

namespace App\Controller\Migration;

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
use Ramsey\Uuid\Uuid;

/**
 * Fase 2c — Revoga permissões concedidas automaticamente pelo trigger seed_permission.
 *
 * O trigger seed_permission (disparado no INSERT em contract_user, Fase 2b) concede
 * TODAS as 52 permissões ao usuário × contrato automaticamente. Este controller
 * remove as permissões que eram false no sistema legado.
 *
 * Não herda AbstractMigrationController: a operação é DELETE (não INSERT)
 * e permission_users não tem id próprio nem timestamps.
 *
 * OBRIGATÓRIO: executar APÓS a Fase 2b (contract-users). Se executado antes,
 * os DELETEs serão silenciosos e o usuário ficará com todas as permissões.
 */
#[Controller(prefix: '/api/v1/migration')]
#[Middlewares([ApiTokenMiddleware::class, RateLimitMiddleware::class])]
#[HyperfServer('http')]
class UserPermissionMigrationController
{
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

    private const MAX_BATCH_SIZE = 500;

    private const ENTITY = 'user_permissions';

    /**
     * Mapeamento de flags booleanas do sistema legado para chaves module:action
     * conforme cadastradas em conciliador_web.permissions (colunas module + name).
     *
     * Formato: 'nome_flag_legado' => ['module:action', ...]
     * Uma flag pode mapear para múltiplas permissões.
     *
     * Pré-requisito: rodar `php bin/hyperf.php migration:seed-lookups permissions`
     * para popular o lookup_cache com as 52 permissões antes de usar este endpoint.
     *
     * ATENÇÃO: preencher este mapa antes de executar a Fase 2c.
     */
    private const PERMISSION_MAP = [
        // Usuários
        'can_create_user'          => ['user:create'],
        'can_edit_user'            => ['user:update'],
        'can_delete_user'          => ['user:delete'],
        // Empresas
        'can_create_company'       => ['company:create'],
        'can_edit_company'         => ['company:update'],
        'can_delete_company'       => ['company:delete'],
        // Layouts
        'can_create_layout'        => ['layout:create'],
        'can_edit_layout'          => ['layout:update'],
        'can_delete_layout'        => ['layout:delete'],
        // Regras compartilhadas (módulo 'rules' no conciliador_web)
        'can_create_shared_rules'  => ['rules:create'],
        'can_edit_shared_rules'    => ['rules:update'],
        'can_delete_shared_rules'  => ['rules:delete'],
        // Importação
        'can_manage_import'        => ['import:create', 'import:update', 'import:delete'],
        // Exportação
        'can_manage_export'        => ['export:create', 'export:update', 'export:delete'],
        // Conciliação
        'can_manage_confrontation' => ['confrontation:create', 'confrontation:update', 'confrontation:delete'],
    ];

    #[OA\Post(
        path: '/api/v1/migration/user-permissions',
        summary: 'Revogar permissões de usuários (Fase 2c)',
        description: <<<'DESC'
        Remove permissões específicas de `permission_users` para usuários migrados. Fase 2c — executar APÓS a Fase 2b (contract-users). Max batch: 500.

        **Contexto:** o trigger `seed_permission` (disparado no INSERT em `contract_user`, Fase 2b) concede automaticamente TODAS as 52 permissões ao par usuário × contrato. Este endpoint revoga as que eram `false` no sistema legado.

        **Semântica dos flags:**
        - `false` → revoga a permissão (DELETE em `permission_users`)
        - `true` → mantém (trigger já concedeu, sem ação)
        - `null` ou ausente → mantém (sem ação)

        **Flags disponíveis e permissões revogadas:**
        | Flag legado | Permissões revogadas |
        |---|---|
        | `can_create_user` | `user:create` |
        | `can_edit_user` | `user:update` |
        | `can_delete_user` | `user:delete` |
        | `can_create_company` | `company:create` |
        | `can_edit_company` | `company:update` |
        | `can_delete_company` | `company:delete` |
        | `can_create_layout` | `layout:create` |
        | `can_edit_layout` | `layout:update` |
        | `can_delete_layout` | `layout:delete` |
        | `can_create_shared_rules` | `rules:create` |
        | `can_edit_shared_rules` | `rules:update` |
        | `can_delete_shared_rules` | `rules:delete` |
        | `can_manage_import` | `import:create`, `import:update`, `import:delete` |
        | `can_manage_export` | `export:create`, `export:update`, `export:delete` |
        | `can_manage_confrontation` | `confrontation:create`, `confrontation:update`, `confrontation:delete` |

        **Pré-requisito:** `php bin/hyperf.php migration:seed-lookups permissions`
        DESC,
        tags: ['Migration - Sync'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/X-Contract-Id')],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['batch'],
                properties: [
                    new OA\Property(
                        property: 'batch',
                        type: 'array',
                        maxItems: 500,
                        items: new OA\Items(
                            required: ['legacy_user_id'],
                            properties: [
                                new OA\Property(property: 'legacy_user_id', type: 'string', example: 'USR-001'),
                            ],
                            additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
                            example: [
                                'legacy_user_id'       => 'USR-002',
                                'can_create_layout'    => false,
                                'can_edit_layout'      => false,
                                'can_delete_layout'    => false,
                                'can_create_shared_rules' => false,
                                'can_edit_shared_rules'   => false,
                                'can_delete_shared_rules' => false,
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Revogação concluída',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'deleted', type: 'integer', example: 10),
                        new OA\Property(property: 'failed',  type: 'integer', example: 0),
                        new OA\Property(property: 'errors',  type: 'array',   items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido',               content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Batch vazio ou excede limite',  content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido',           content: new OA\JsonContent(ref: '#/components/schemas/RateLimitResponse')),
        ]
    )]
    #[PostMapping(path: 'user-permissions')]
    public function migrate(): array
    {
        $requestId = Uuid::uuid4()->toString();
        $batch = $this->request->input('batch', []);

        if (empty($batch)) {
            return ['error' => 'Empty batch', 'code' => 422];
        }

        if (\count($batch) > self::MAX_BATCH_SIZE) {
            return ['error' => 'Batch size exceeds maximum of ' . self::MAX_BATCH_SIZE, 'code' => 422];
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

        $deleted = 0;
        $failed  = 0;
        $errors  = [];
        $recordLogs = [];

        foreach ($batch as $index => $record) {
            $legacyUserId = $record['legacy_user_id'] ?? null;

            if (empty($legacyUserId)) {
                $errors[] = ['index' => $index, 'error' => 'Missing legacy_user_id'];
                $recordLogs[] = [
                    'legacy_id' => null,
                    'new_id' => null,
                    'status' => 'failed',
                    'error_message' => 'Missing legacy_user_id',
                ];
                $failed++;
                continue;
            }

            $userId = $this->idMappingService->resolve('users', (string) $legacyUserId, $contractId);

            if ($userId === null) {
                $errors[] = [
                    'index'          => $index,
                    'legacy_user_id' => $legacyUserId,
                    'error'          => "User mapping not found for legacy_user_id '{$legacyUserId}'",
                ];
                $recordLogs[] = [
                    'legacy_id' => (string) $legacyUserId,
                    'new_id' => null,
                    'status' => 'failed',
                    'error_message' => "User mapping not found for legacy_user_id '{$legacyUserId}'",
                ];
                $failed++;
                continue;
            }

            $permissionKeysToRevoke = $this->collectRevokeKeys($record);
            $recordFailed = false;

            foreach ($permissionKeysToRevoke as $permissionKey) {
                $permissionId = $this->lookupCacheService->resolve('permissions', $permissionKey);

                if ($permissionId === null) {
                    $errors[] = [
                        'index'          => $index,
                        'legacy_user_id' => $legacyUserId,
                        'permission_key' => $permissionKey,
                        'error'          => "Permission '{$permissionKey}' not found in lookup cache. Run: php bin/hyperf.php migration:seed-lookups permissions",
                    ];
                    $recordFailed = true;
                    $failed++;
                    continue;
                }

                try {
                    Db::connection('conciliador_web')
                        ->table('permission_users')
                        ->where('user_id', $userId)
                        ->where('contract_id', $contractId)
                        ->where('permission_id', $permissionId)
                        ->delete();

                    $deleted++;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'index'          => $index,
                        'legacy_user_id' => $legacyUserId,
                        'permission_key' => $permissionKey,
                        'error'          => $e->getMessage(),
                    ];
                    $recordFailed = true;
                    $failed++;
                }
            }

            $recordLogs[] = [
                'legacy_id' => (string) $legacyUserId,
                'new_id' => null,
                'status' => $recordFailed ? 'failed' : 'deleted',
                'error_message' => $recordFailed ? 'One or more permission deletions failed.' : null,
            ];
        }

        $response = [
            'deleted' => $deleted,
            'failed'  => $failed,
            'errors'  => $errors,
        ];

        $this->auditService->close($requestId, $response);

        if ($this->auditService->shouldLogRecords(self::ENTITY)) {
            $this->auditService->logRecords($requestId, $contractId, self::ENTITY, $recordLogs);
        }

        return $response;
    }

    /**
     * Retorna as chaves module:action que devem ser revogadas para o registro dado.
     *
     * Apenas flags com valor estritamente false são revogadas.
     * Flags true, null ou ausentes são ignoradas.
     *
     * @return string[]  ex: ['import:view', 'rules:delete']
     */
    private function collectRevokeKeys(array $record): array
    {
        $keys = [];

        foreach (self::PERMISSION_MAP as $flagName => $permissionKeys) {
            if (array_key_exists($flagName, $record) && $record[$flagName] === false) {
                foreach ($permissionKeys as $key) {
                    $keys[] = $key;
                }
            }
        }

        return array_unique($keys);
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
