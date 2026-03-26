<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Controller;

use App\Controller\Migration\ContractMigrationController;
use App\Service\IdMappingService;
use App\Service\MigrationBatchService;
use App\Service\ParallelInsertService;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Support\MessageBag;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContractMigrationController::class)]
final class ContractMigrationControllerTest extends UnitTestCase
{
    public function testMigrateReturnsErrorForEmptyBatch(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('input')
            ->with('batch', [])
            ->willReturn([]);

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController($request, $insertService);

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
            ->willReturn(array_fill(0, 101, ['name' => 'Empresa']));

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController($request, $insertService);

        $this->assertSame(
            ['error' => 'Batch size exceeds maximum of 100', 'code' => 422],
            $controller->migrate()
        );
    }

    public function testMigrateTransformsAndPersistsValidBatch(): void
    {
        $batch = [[
            'legacy_id'       => 'LEG-001',
            'cpf_cnpj'        => '12345678000195',
            'corporate_name'  => 'Empresa Teste Ltda',
            'name'            => 'Empresa Teste',
            'email'           => 'contato@teste.com',
            'phone'           => '11987654321',
            'contractor_type' => 'company',
            'company_count'   => 5,
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->exactly(2))
            ->method('input')
            ->with('batch', [])
            ->willReturn($batch);
        $request->expects($this->once())
            ->method('header')
            ->with('X-Contract-Id', '')
            ->willReturn('header-contract');
        $request->expects($this->once())
            ->method('getAttribute')
            ->with('contract_id', 'header-contract')
            ->willReturn('contract-uuid-1');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(false);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->method('make')->willReturn($validator);

        $persistedRecords = [];

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->once())
            ->method('insertSync')
            ->with(
                'contracts',
                $this->callback(function (array $records) use (&$persistedRecords): bool {
                    $persistedRecords = $records;
                    $record = $records[0];

                    $this->assertArrayNotHasKey('legacy_id', $record);
                    $this->assertSame('12345678000195', $record['cpf_cnpj']);
                    $this->assertSame('Empresa Teste Ltda', $record['corporate_name']);
                    $this->assertSame('company', $record['contractor_type']);
                    $this->assertMatchesRegularExpression(self::UUID_PATTERN, $record['id']);
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['created_at']);
                    $this->assertSame($record['created_at'], $record['updated_at']);

                    return true;
                }),
                'conciliador_web'
            )
            ->willReturn(['inserted' => 1, 'failed' => 0, 'errors' => []]);

        $idMappingService = $this->createMock(IdMappingService::class);
        $idMappingService->expects($this->once())
            ->method('storeBatch')
            ->with(
                'contracts',
                $this->callback(function (array $mappings): bool {
                    return isset($mappings['LEG-001'])
                        && \is_string($mappings['LEG-001'])
                        && preg_match(self::UUID_PATTERN, $mappings['LEG-001']) === 1;
                }),
                'contract-uuid-1'
            );

        $controller = $this->createController($request, $insertService, $idMappingService, null, $validatorFactory);

        $result = $controller->migrate();

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame([], $result['errors']);
        $this->assertArrayHasKey('LEG-001', $result['id_mappings']);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $result['id_mappings']['LEG-001']);
        $this->assertSame($persistedRecords[0]['id'], $result['id_mappings']['LEG-001']);
    }

    public function testMigrateReportsValidationErrorForInvalidRecord(): void
    {
        $batch = [[
            'legacy_id' => 'LEG-BAD',
            'name'      => 'Empresa',
            // missing: cpf_cnpj, corporate_name, contractor_type, company_count
        ]];

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->exactly(2))
            ->method('input')
            ->with('batch', [])
            ->willReturn($batch);
        $request->method('header')->willReturn('contract-1');
        $request->method('getAttribute')->willReturn('contract-1');

        $messageBag = $this->createMock(MessageBag::class);
        $messageBag->method('toArray')->willReturn([
            'cpf_cnpj'        => ['The cpf_cnpj field is required.'],
            'corporate_name'  => ['The corporate_name field is required.'],
            'contractor_type' => ['The contractor_type field is required.'],
            'company_count'   => ['The company_count field is required.'],
        ]);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('fails')->willReturn(true);
        $validator->method('errors')->willReturn($messageBag);

        $validatorFactory = $this->createMock(ValidatorFactoryInterface::class);
        $validatorFactory->method('make')->willReturn($validator);

        $insertService = $this->createMock(ParallelInsertService::class);
        $insertService->expects($this->never())->method('insertSync');

        $controller = $this->createController($request, $insertService, null, null, $validatorFactory);

        $result = $controller->migrate();

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(0, $result['errors'][0]['index']);
        $this->assertSame('LEG-BAD', $result['errors'][0]['legacy_id']);
        $this->assertArrayHasKey('cpf_cnpj', $result['errors'][0]['validation_errors']);
    }

    private function createController(
        RequestInterface $request,
        ParallelInsertService $insertService,
        ?IdMappingService $idMappingService = null,
        ?MigrationBatchService $batchService = null,
        ?ValidatorFactoryInterface $validatorFactory = null
    ): ContractMigrationController {
        $controller = new ContractMigrationController();
        $this->injectProperty($controller, 'request', $request);
        $this->injectProperty($controller, 'insertService', $insertService);
        $this->injectProperty($controller, 'idMappingService', $idMappingService ?? $this->createStub(IdMappingService::class));
        $this->injectProperty($controller, 'batchService', $batchService ?? $this->createStub(MigrationBatchService::class));
        $this->injectProperty($controller, 'validatorFactory', $validatorFactory ?? $this->createStub(ValidatorFactoryInterface::class));

        return $controller;
    }
}
