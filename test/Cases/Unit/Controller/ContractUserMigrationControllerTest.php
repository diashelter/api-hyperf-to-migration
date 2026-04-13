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

use App\Controller\Migration\ContractUserMigrationController;
use App\Service\IdMappingService;
use App\Service\LookupCacheService;
use App\Service\MigrationAuditService;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Support\MessageBag;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @internal
 */
#[CoversClass(ContractUserMigrationController::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContractUserMigrationControllerTest extends UnitTestCase
{
    public function testMigrateReturnsErrorForEmptyBatch(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn([]);

        $controller = $this->createController($request);

        $this->assertSame(
            ['error' => 'Empty batch', 'code' => 422],
            $controller->migrate()
        );
    }

    public function testMigrateReturnsErrorWhenBatchExceedsLimit(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn(array_fill(0, 501, ['user_id' => 'x']));

        $controller = $this->createController($request);

        $this->assertSame(
            ['error' => 'Batch size exceeds maximum of 500', 'code' => 422],
            $controller->migrate()
        );
    }

    public function testMigrateResolvesLegacyFKsAndPassesResolvedUuidsToValidator(): void
    {
        $batch = [[
            'legacy_user_id' => 'USR-001',
            'legacy_contract_id' => 'LEG-001',
            'legacy_role_id' => 'ROLE-ADMIN',
            'contract_admin' => true,
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

        $userUuid = 'aaaaaaaa-0000-4000-8000-000000000001';
        $contractUuid = 'bbbbbbbb-0000-4000-8000-000000000002';
        $roleUuid = 'cccccccc-0000-4000-8000-000000000003';

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->exactly(2))
            ->method('resolve')
            ->willReturnMap([
                ['users', 'USR-001', 'contract-uuid-1', $userUuid],
                ['contracts', 'LEG-001', 'contract-uuid-1', $contractUuid],
            ]);
        $lookupCacheService = $this->createMock(LookupCacheService::class);
        $lookupCacheService->expects($this->once())
            ->method('resolve')
            ->with('roles', 'ROLE-ADMIN')
            ->willReturn($roleUuid);

        // Make the validator fail so no DB call is needed, but assert the record
        // passed to make() already has the resolved UUIDs and no legacy keys.
        $messageBag = $this->createMock(MessageBag::class);
        $messageBag->method('toArray')->willReturn(['user_id' => ['invalid']]);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(true);
        $validator->method('errors')->willReturn($messageBag);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->once())
            ->method('make')
            ->with(
                $this->callback(function (array $record) use ($userUuid, $contractUuid, $roleUuid): bool {
                    $this->assertSame($userUuid, $record['user_id']);
                    $this->assertSame($contractUuid, $record['contract_id']);
                    $this->assertSame($roleUuid, $record['role_id']);
                    $this->assertArrayNotHasKey('legacy_user_id', $record);
                    $this->assertArrayNotHasKey('legacy_contract_id', $record);
                    $this->assertArrayNotHasKey('legacy_role_id', $record);

                    return true;
                }),
                $this->anything()
            )
            ->willReturn($validator);

        $controller = $this->createController($request, $idMappingService, $lookupCacheService, $validatorFactory);

        $result = $controller->migrate();

        // Record failed validation (by design), but FK resolution was verified above
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['failed']);
    }

    public function testMigrateReportsValidationErrorWhenLegacyIdsDoNotResolve(): void
    {
        $batch = [[
            'legacy_user_id' => 'USR-MISSING',
            'legacy_contract_id' => 'LEG-MISSING',
            'legacy_role_id' => 'ROLE-MISSING',
            'contract_admin' => false,
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn($batch);
        $request->method('header')->willReturn('contract-1');
        $request->method('getAttribute')->willReturn('contract-1');

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->method('resolve')->willReturn(null);

        $lookupCacheService = $this->createMock(LookupCacheService::class);
        $lookupCacheService->method('resolve')->willReturn(null);

        $messageBag = $this->createMock(MessageBag::class);
        $messageBag->method('toArray')->willReturn([
            'user_id' => ['The user_id field is required.'],
            'contract_id' => ['The contract_id field is required.'],
            'role_id' => ['The role_id field is required.'],
        ]);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(true);
        $validator->method('errors')->willReturn($messageBag);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->method('make')->willReturn($validator);

        $controller = $this->createController($request, $idMappingService, $lookupCacheService, $validatorFactory);

        $result = $controller->migrate();

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(0, $result['errors'][0]['index']);
        $this->assertNull($result['errors'][0]['legacy_id']);
        $this->assertArrayHasKey('user_id', $result['errors'][0]['validation_errors']);
        $this->assertArrayHasKey('contract_id', $result['errors'][0]['validation_errors']);
        $this->assertArrayHasKey('role_id', $result['errors'][0]['validation_errors']);
    }

    public function testMigrateStripsMetaFieldsFromPivotRecordBeforeValidation(): void
    {
        // Ensures legacy_id, id, created_at, updated_at are removed before
        // the record is validated and potentially inserted into the pivot table.
        $batch = [[
            'legacy_id' => 'LEG-001',
            'id' => 'some-uuid',
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
            'user_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'contract_id' => 'bbbbbbbb-0000-4000-8000-000000000002',
            'role_id' => 'cccccccc-0000-4000-8000-000000000003',
            'contract_admin' => true,
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn($batch);
        $request->method('header')->willReturn('contract-1');
        $request->method('getAttribute')->willReturn('contract-1');

        $messageBag = $this->createMock(MessageBag::class);
        $messageBag->method('toArray')->willReturn(['user_id' => ['invalid']]);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(true);
        $validator->method('errors')->willReturn($messageBag);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->once())
            ->method('make')
            ->with(
                $this->callback(function (array $record): bool {
                    $this->assertArrayNotHasKey('legacy_id', $record);
                    $this->assertArrayNotHasKey('id', $record);
                    $this->assertArrayNotHasKey('created_at', $record);
                    $this->assertArrayNotHasKey('updated_at', $record);

                    return true;
                }),
                $this->anything()
            )
            ->willReturn($validator);

        $controller = $this->createController($request, null, null, $validatorFactory);

        $result = $controller->migrate();

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['failed']);
    }

    public function testMigrateUsesInsertOrIgnoreForDuplicatePivotRowsAndAuditsRequest(): void
    {
        $batch = [
            [
                'legacy_user_id' => 'USR-001',
                'legacy_contract_id' => 'LEG-001',
                'legacy_role_id' => 'ROLE-ADMIN',
                'contract_admin' => true,
            ],
            [
                'legacy_user_id' => 'USR-001',
                'legacy_contract_id' => 'LEG-001',
                'legacy_role_id' => 'ROLE-ADMIN',
                'contract_admin' => true,
            ],
        ];

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

        $userUuid = 'aaaaaaaa-0000-4000-8000-000000000001';
        $contractUuid = 'bbbbbbbb-0000-4000-8000-000000000002';
        $roleUuid = 'cccccccc-0000-4000-8000-000000000003';

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->exactly(4))
            ->method('resolve')
            ->willReturnMap([
                ['users', 'USR-001', 'contract-uuid-1', $userUuid],
                ['contracts', 'LEG-001', 'contract-uuid-1', $contractUuid],
            ]);

        $lookupCacheService = $this->createMock(LookupCacheService::class);
        $lookupCacheService->expects($this->exactly(2))
            ->method('resolve')
            ->with('roles', 'ROLE-ADMIN')
            ->willReturn($roleUuid);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(false);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->expects($this->exactly(2))
            ->method('make')
            ->willReturn($validator);

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();
        $builder = Mockery::mock();

        $db->shouldReceive('connection')->once()->with('conciliador_web')->andReturn($connection);
        $connection->shouldReceive('beginTransaction')->once();
        $connection->shouldReceive('table')->once()->with('contract_user')->andReturn($builder);
        $builder->shouldReceive('insertOrIgnore')
            ->once()
            ->with(Mockery::on(function (array $records) use ($userUuid, $contractUuid, $roleUuid): bool {
                return count($records) === 2
                    && $records[0]['user_id'] === $userUuid
                    && $records[0]['contract_id'] === $contractUuid
                    && $records[0]['role_id'] === $roleUuid;
            }))
            ->andReturn(1);
        $connection->shouldReceive('commit')->once();
        $connection->shouldReceive('rollBack')->never();

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
                'contract_users',
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
                    $this->assertSame(1, $result['inserted']);
                    $this->assertSame(1, $result['skipped']);
                    $this->assertSame(0, $result['failed']);
                    $this->assertSame([], $result['errors']);

                    return true;
                })
            );
        $auditService->expects($this->once())
            ->method('shouldLogRecords')
            ->with('contract_users')
            ->willReturn(true);
        $auditService->expects($this->once())
            ->method('logRecords')
            ->with(
                $this->callback(function (string $requestId) use (&$capturedRequestId): bool {
                    return $requestId === $capturedRequestId;
                }),
                'contract-uuid-1',
                'contract_users',
                $this->callback(function (array $recordLogs): bool {
                    $this->assertCount(2, $recordLogs);
                    $this->assertSame('inserted', $recordLogs[0]['status']);
                    $this->assertSame('skipped_duplicate', $recordLogs[1]['status']);

                    return true;
                })
            );

        $controller = $this->createController(
            $request,
            $idMappingService,
            $lookupCacheService,
            $validatorFactory,
            $auditService
        );

        $result = $controller->migrate();

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['id_mappings']);
    }

    private function createController(
        RequestInterface $request,
        ?IdMappingService $idMappingService = null,
        ?LookupCacheService $lookupCacheService = null,
        ?ValidatorFactoryInterface $validatorFactory = null,
        ?MigrationAuditService $auditService = null
    ): ContractUserMigrationController {
        $controller = new ContractUserMigrationController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'idMappingService', $idMappingService ?? $this->createStub(IdMappingService::class));
        $this->injectProperty($controller, 'lookupCacheService', $lookupCacheService ?? $this->createStub(LookupCacheService::class));
        $this->injectProperty($controller, 'validatorFactory', $validatorFactory ?? $this->createStub(ValidatorFactoryInterface::class));
        $this->injectProperty($controller, 'auditService', $auditService ?? $this->createStub(MigrationAuditService::class));

        return $controller;
    }
}
