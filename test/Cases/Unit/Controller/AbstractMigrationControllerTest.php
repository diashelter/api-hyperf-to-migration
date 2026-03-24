<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Controller;

use App\Controller\AbstractMigrationController;
use App\Service\IdMappingService;
use Hyperf\HttpServer\Contract\RequestInterface;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AbstractMigrationController::class)]
final class AbstractMigrationControllerTest extends UnitTestCase
{
    public function testGetContractIdPrefersRequestAttributeOverHeader(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())->method('header')->with('X-Contract-Id', '')->willReturn('header-contract');
        $request->expects($this->once())->method('getAttribute')->with('contract_id', 'header-contract')->willReturn('attribute-contract');

        $controller = new class extends AbstractMigrationController {
            protected function getTable(): string
            {
                return 'table';
            }

            protected function getEntity(): string
            {
                return 'entity';
            }

            protected function getMaxBatchSize(): int
            {
                return 1;
            }

            public function exposedGetContractId(): string
            {
                return $this->getContractId();
            }
        };

        $this->injectProperty($controller, 'request', $request);

        $this->assertSame('attribute-contract', $controller->exposedGetContractId());
    }

    public function testDefaultResolveForeignKeysReturnsRecordUnchanged(): void
    {
        $controller = new class extends AbstractMigrationController {
            protected function getTable(): string
            {
                return 'table';
            }

            protected function getEntity(): string
            {
                return 'entity';
            }

            protected function getMaxBatchSize(): int
            {
                return 1;
            }

            public function exposedResolveForeignKeys(array $record, string $contractId): array
            {
                return $this->resolveForeignKeys($record, $contractId);
            }
        };

        $this->injectProperty($controller, 'idMappingService', $this->createMock(IdMappingService::class));

        $record = ['name' => 'ACME', 'legacy_contract_id' => 'legacy-1'];

        $this->assertSame($record, $controller->exposedResolveForeignKeys($record, 'contract-1'));
    }
}
