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

namespace HyperfTest\Cases\Unit\Controller;

use App\Controller\Migration\UserPermissionMigrationController;
use App\Service\IdMappingService;
use App\Service\LookupCacheService;
use App\Service\MigrationAuditService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(UserPermissionMigrationController::class)]
final class UserPermissionMigrationControllerTest extends UnitTestCase
{
    public function testMigrateAuditsDeleteFlowWhenRecordIsInvalid(): void
    {
        $batch = [[
            'can_view_import' => false,
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn($batch);
        $request->expects($this->exactly(2))
            ->method('header')
            ->willReturnMap([
                ['X-Contract-Id', '', 'header-contract'],
                ['user-agent', '', 'phpunit-agent'],
            ]);
        $request->expects($this->once())
            ->method('getAttribute')
            ->with('contract_id', 'header-contract')
            ->willReturn('contract-uuid-1');
        $request->expects($this->once())
            ->method('getServerParams')
            ->willReturn(['remote_addr' => '127.0.0.1']);

        $capturedRequestId = null;
        $auditService = $this->createMock(MigrationAuditService::class);
        $auditService->expects($this->once())
            ->method('open')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    $capturedRequestId = $requestId;

                    return preg_match(self::UUID_PATTERN, $requestId) === 1;
                }),
                'contract-uuid-1',
                'user_permissions',
                $batch,
                '127.0.0.1',
                'phpunit-agent'
            );
        $auditService->expects($this->once())
            ->method('close')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    return $requestId === $capturedRequestId;
                }),
                $this->callback(function (array $result): bool {
                    $this->assertSame(0, $result['deleted']);
                    $this->assertSame(1, $result['failed']);
                    $this->assertCount(1, $result['errors']);
                    $this->assertSame('Missing legacy_user_id', $result['errors'][0]['error']);

                    return true;
                })
            );
        $auditService->expects($this->once())
            ->method('shouldLogRecords')
            ->with('user_permissions')
            ->willReturn(true);
        $auditService->expects($this->once())
            ->method('logRecords')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    return $requestId === $capturedRequestId;
                }),
                'contract-uuid-1',
                'user_permissions',
                $this->callback(function (array $recordLogs): bool {
                    $this->assertCount(1, $recordLogs);
                    $this->assertSame('failed', $recordLogs[0]['status']);
                    $this->assertNull($recordLogs[0]['legacy_id']);
                    $this->assertSame('Missing legacy_user_id', $recordLogs[0]['error_message']);

                    return true;
                })
            );

        $controller = $this->createController($request, auditService: $auditService);

        $result = $controller->migrate();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
    }

    private function createController(
        RequestInterface $request,
        ?IdMappingService $idMappingService = null,
        ?LookupCacheService $lookupCacheService = null,
        ?ValidatorFactoryInterface $validatorFactory = null,
        ?MigrationAuditService $auditService = null
    ): UserPermissionMigrationController {
        $controller = new UserPermissionMigrationController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'idMappingService', $idMappingService ?? $this->createStub(IdMappingService::class));
        $this->injectProperty($controller, 'lookupCacheService', $lookupCacheService ?? $this->createStub(LookupCacheService::class));
        $this->injectProperty($controller, 'validatorFactory', $validatorFactory ?? $this->createStub(ValidatorFactoryInterface::class));
        $this->injectProperty($controller, 'auditService', $auditService ?? $this->createStub(MigrationAuditService::class));

        return $controller;
    }
}
