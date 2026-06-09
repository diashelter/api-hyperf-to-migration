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

namespace HyperfTest\Cases\Unit\Service;

use App\Service\LegacyDuplicateValidationService;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(LegacyDuplicateValidationService::class)]
final class LegacyDuplicateValidationServiceTest extends UnitTestCase
{
    public function testBuildSummaryPayloadWithViolations(): void
    {
        $service = new LegacyDuplicateValidationService();
        $rules = [
            ['entity' => 'users', 'rule' => 'duplicate_email'],
            ['entity' => 'users', 'rule' => 'missing_email'],
            ['entity' => 'companies', 'rule' => 'invalid_tax_regime'],
            ['entity' => 'companies', 'rule' => 'missing_plan_reference'],
        ];
        $violations = [
            ['rule' => 'duplicate_email'],
            ['rule' => 'invalid_tax_regime'],
        ];

        $payload = $service->buildSummaryPayload('cont_focons', $violations, $rules);

        $this->assertSame('cont_focons', $payload['legacy_db']);
        $this->assertTrue($payload['has_violations']);
        $this->assertSame(2, $payload['summary']['entities_checked']);
        $this->assertSame(4, $payload['summary']['rules_checked']);
        $this->assertSame(2, $payload['summary']['violations']);
        $this->assertSame($violations, $payload['violations']);
    }

    public function testBuildSummaryPayloadWithoutViolations(): void
    {
        $service = new LegacyDuplicateValidationService();
        $rules = [
            ['entity' => 'users'],
            ['entity' => 'companies'],
        ];

        $payload = $service->buildSummaryPayload('cont_krypton', [], $rules);

        $this->assertSame('cont_krypton', $payload['legacy_db']);
        $this->assertFalse($payload['has_violations']);
        $this->assertSame(2, $payload['summary']['entities_checked']);
        $this->assertSame(2, $payload['summary']['rules_checked']);
        $this->assertSame(0, $payload['summary']['violations']);
        $this->assertSame([], $payload['violations']);
    }

    public function testValidateExecutesRulesAndBuildsCompatibleViolationPayload(): void
    {
        $service = new LegacyDuplicateValidationService();
        $ruleCount = count($this->serviceRules($service));

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();
        $selectCalls = 0;

        $db->shouldReceive('connection')
            ->times($ruleCount + 1)
            ->with('legacy_database_cont_focons')
            ->andReturn($connection);

        $connection->shouldReceive('select')
            ->times($ruleCount)
            ->with(Mockery::type('string'))
            ->andReturnUsing(static function () use (&$selectCalls): array {
                $selectCalls++;

                if ($selectCalls === 1) {
                    return [
                        (object) [
                            'value' => 'user@example.com',
                            'duplicate_count' => 3,
                            'legacy_ids' => '1,2,3',
                        ],
                    ];
                }

                return [];
            });

        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn((object) [
                'total_groups' => 1,
                'total_records' => 3,
            ]);

        $payload = $service->validate('cont_focons', 'legacy_database_cont_focons');

        $this->assertSame('cont_focons', $payload['legacy_db']);
        $this->assertTrue($payload['has_violations']);
        $this->assertSame($ruleCount, $payload['summary']['rules_checked']);
        $this->assertSame(1, $payload['summary']['violations']);
        $this->assertSame('users', $payload['violations'][0]['entity']);
        $this->assertSame('missing_email', $payload['violations'][0]['rule']);
        $this->assertSame(1, $payload['violations'][0]['total_groups']);
        $this->assertSame(3, $payload['violations'][0]['total_records']);
        $this->assertSame('user@example.com', $payload['violations'][0]['samples'][0]['value']);
        $this->assertSame(3, $payload['violations'][0]['samples'][0]['count']);
        $this->assertSame(['1', '2', '3'], $payload['violations'][0]['samples'][0]['legacy_ids']);
    }

    public function testValidateIgnoresRulesWithoutSamples(): void
    {
        $service = new LegacyDuplicateValidationService();
        $ruleCount = count($this->serviceRules($service));

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();

        $db->shouldReceive('connection')
            ->times($ruleCount)
            ->with('legacy_database_cont_krypton')
            ->andReturn($connection);

        $connection->shouldReceive('select')
            ->times($ruleCount)
            ->with(Mockery::type('string'))
            ->andReturn([]);
        $connection->shouldReceive('selectOne')->never();

        $payload = $service->validate('cont_krypton', 'legacy_database_cont_krypton');

        $this->assertFalse($payload['has_violations']);
        $this->assertSame($ruleCount, $payload['summary']['rules_checked']);
        $this->assertSame(0, $payload['summary']['violations']);
        $this->assertSame([], $payload['violations']);
    }

    public function testExplodeLegacyIdsTrimsAndDropsEmptyItems(): void
    {
        $service = new LegacyDuplicateValidationService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('explodeLegacyIds');
        $method->setAccessible(true);

        $this->assertSame(['1', '2', 'abc'], $method->invoke($service, ' 1, 2,, abc ,'));
        $this->assertSame([], $method->invoke($service, ''));
    }

    public function testPeopleRulesTreatCpfcnpjEmptyAndNullAsNoCpf(): void
    {
        $service = new LegacyDuplicateValidationService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('peopleRules');
        $method->setAccessible(true);

        $rules = $method->invoke($service);

        $this->assertStringContainsString(
            "NULLIF(REGEXP_REPLACE(COALESCE(cpfcnpj, ''), '[^0-9]', '', 'g'), '') IS NOT NULL",
            $rules[1]['samples_sql']
        );
        $this->assertStringContainsString(
            "NULLIF(REGEXP_REPLACE(COALESCE(cpfcnpj, ''), '[^0-9]', '', 'g'), '') IS NULL",
            $rules[2]['samples_sql']
        );
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function serviceRules(LegacyDuplicateValidationService $service): array
    {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('rules');
        $method->setAccessible(true);

        return $method->invoke($service);
    }
}
