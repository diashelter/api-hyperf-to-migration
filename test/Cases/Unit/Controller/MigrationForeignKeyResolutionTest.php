<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Controller;

use App\Controller\Migration\ConfrontationMigrationController;
use App\Controller\Migration\ConfrontationRecordMigrationController;
use App\Controller\Migration\ContractMigrationController;
use App\Controller\Migration\ImportMigrationController;
use App\Controller\Migration\ImportSessionMigrationController;
use App\Controller\Migration\LayoutMigrationController;
use App\Controller\Migration\PeopleMigrationController;
use App\Controller\Migration\PlanItemMigrationController;
use App\Controller\Migration\PlanMigrationController;
use App\Controller\Migration\RuleMigrationController;
use App\Controller\Migration\RulesSharingMigrationController;
use App\Service\IdMappingService;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

#[CoversClass(ContractMigrationController::class)]
#[CoversClass(PlanMigrationController::class)]
#[CoversClass(PlanItemMigrationController::class)]
#[CoversClass(PeopleMigrationController::class)]
#[CoversClass(RulesSharingMigrationController::class)]
#[CoversClass(LayoutMigrationController::class)]
#[CoversClass(ImportSessionMigrationController::class)]
#[CoversClass(ImportMigrationController::class)]
#[CoversClass(RuleMigrationController::class)]
#[CoversClass(ConfrontationMigrationController::class)]
#[CoversClass(ConfrontationRecordMigrationController::class)]
final class MigrationForeignKeyResolutionTest extends UnitTestCase
{
    #[DataProvider('controllerResolutionProvider')]
    public function testControllersResolveLegacyForeignKeys(
        string $controllerClass,
        array $inputRecord,
        array $resolvedValues,
        array $expectedRecord
    ): void {
        $calls = [];
        $idMappingService = $this->createMock(IdMappingService::class);

        if ($resolvedValues === []) {
            $idMappingService->expects($this->never())->method('resolve');
        } else {
            $idMappingService->expects($this->exactly(count($resolvedValues)))
                ->method('resolve')
                ->willReturnCallback(function (string $entity, string $legacyId, string $contractId) use (&$calls, $resolvedValues): ?string {
                    $calls[] = [$entity, $legacyId, $contractId];

                    return $resolvedValues["{$entity}:{$legacyId}:{$contractId}"] ?? null;
                });
        }

        $controller = new $controllerClass();
        $this->injectProperty($controller, 'idMappingService', $idMappingService);

        $method = new ReflectionMethod($controller, 'resolveForeignKeys');
        $actualRecord = $method->invoke($controller, $inputRecord, 'contract-1');

        $this->assertSame(array_values(array_map(
            static fn(string $key): array => explode(':', $key, 3),
            array_keys($resolvedValues)
        )), $calls);
        $this->assertSame($expectedRecord, $actualRecord);
    }

    public static function controllerResolutionProvider(): array
    {
        return [
            'contract controller keeps records unchanged' => [
                ContractMigrationController::class,
                ['name' => 'Contract A'],
                [],
                ['name' => 'Contract A'],
            ],
            'plan controller resolves contract' => [
                PlanMigrationController::class,
                ['legacy_contract_id' => 'legacy-contract-1'],
                ['contracts:legacy-contract-1:contract-1' => 'contract-uuid-1'],
                ['contract_id' => 'contract-uuid-1'],
            ],
            'plan item controller resolves plan' => [
                PlanItemMigrationController::class,
                ['legacy_plan_id' => 'legacy-plan-1'],
                ['plans:legacy-plan-1:contract-1' => 'plan-uuid-1'],
                ['plan_id' => 'plan-uuid-1'],
            ],
            'people controller resolves contract' => [
                PeopleMigrationController::class,
                ['legacy_contract_id' => 'legacy-contract-1'],
                ['contracts:legacy-contract-1:contract-1' => 'contract-uuid-1'],
                ['contract_id' => 'contract-uuid-1'],
            ],
            'rules sharing controller resolves contract' => [
                RulesSharingMigrationController::class,
                ['legacy_contract_id' => 'legacy-contract-1'],
                ['contracts:legacy-contract-1:contract-1' => 'contract-uuid-1'],
                ['contract_id' => 'contract-uuid-1'],
            ],
            'layout controller resolves contract' => [
                LayoutMigrationController::class,
                ['legacy_contract_id' => 'legacy-contract-1'],
                ['contracts:legacy-contract-1:contract-1' => 'contract-uuid-1'],
                ['contract_id' => 'contract-uuid-1'],
            ],
            'import session controller resolves import and layout' => [
                ImportSessionMigrationController::class,
                [
                    'legacy_import_id' => 'legacy-import-1',
                    'legacy_layout_id' => 'legacy-layout-1',
                ],
                [
                    'imports:legacy-import-1:contract-1' => 'import-uuid-1',
                    'layouts:legacy-layout-1:contract-1' => 'layout-uuid-1',
                ],
                [
                    'import_id' => 'import-uuid-1',
                    'layout_id' => 'layout-uuid-1',
                ],
            ],
            'import controller resolves all dependencies' => [
                ImportMigrationController::class,
                [
                    'legacy_user_id' => 'legacy-user-1',
                    'legacy_company_id' => 'legacy-company-1',
                    'legacy_contract_id' => 'legacy-contract-1',
                    'legacy_company_layout_id' => 'legacy-layout-1',
                ],
                [
                    'users:legacy-user-1:contract-1' => 'user-uuid-1',
                    'companies:legacy-company-1:contract-1' => 'company-uuid-1',
                    'contracts:legacy-contract-1:contract-1' => 'contract-uuid-1',
                    'company_layout:legacy-layout-1:contract-1' => 'layout-uuid-1',
                ],
                [
                    'user_id' => 'user-uuid-1',
                    'company_id' => 'company-uuid-1',
                    'contract_id' => 'contract-uuid-1',
                    'company_layout_id' => 'layout-uuid-1',
                ],
            ],
            'rule controller resolves company layout and contract' => [
                RuleMigrationController::class,
                [
                    'legacy_company_id' => 'legacy-company-1',
                    'legacy_layout_id' => 'legacy-layout-1',
                    'legacy_contract_id' => 'legacy-contract-1',
                ],
                [
                    'companies:legacy-company-1:contract-1' => 'company-uuid-1',
                    'layouts:legacy-layout-1:contract-1' => 'layout-uuid-1',
                    'contracts:legacy-contract-1:contract-1' => 'contract-uuid-1',
                ],
                [
                    'company_id' => 'company-uuid-1',
                    'layout_id' => 'layout-uuid-1',
                    'contract_id' => 'contract-uuid-1',
                ],
            ],
            'confrontation controller resolves contract and company' => [
                ConfrontationMigrationController::class,
                [
                    'legacy_contract_id' => 'legacy-contract-1',
                    'legacy_company_id' => 'legacy-company-1',
                ],
                [
                    'contracts:legacy-contract-1:contract-1' => 'contract-uuid-1',
                    'companies:legacy-company-1:contract-1' => 'company-uuid-1',
                ],
                [
                    'contract_id' => 'contract-uuid-1',
                    'company_id' => 'company-uuid-1',
                ],
            ],
            'confrontation record controller resolves all dependencies' => [
                ConfrontationRecordMigrationController::class,
                [
                    'legacy_confrontation_id' => 'legacy-confrontation-1',
                    'legacy_import_record_id' => 'legacy-import-record-1',
                    'legacy_import_id' => 'legacy-import-1',
                ],
                [
                    'confrontations:legacy-confrontation-1:contract-1' => 'confrontation-uuid-1',
                    'import_records:legacy-import-record-1:contract-1' => 'import-record-uuid-1',
                    'imports:legacy-import-1:contract-1' => 'import-uuid-1',
                ],
                [
                    'confrontation_id' => 'confrontation-uuid-1',
                    'import_record_id' => 'import-record-uuid-1',
                    'import_id' => 'import-uuid-1',
                ],
            ],
        ];
    }
}
