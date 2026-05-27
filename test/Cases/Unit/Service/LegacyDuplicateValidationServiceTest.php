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
use PHPUnit\Framework\Attributes\CoversClass;

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
            ['entity' => 'users'],
            ['entity' => 'users'],
            ['entity' => 'companies'],
        ];
        $violations = [
            ['rule' => 'duplicate_email'],
            ['rule' => 'duplicate_cnpj'],
        ];

        $payload = $service->buildSummaryPayload('cont_focons', $violations, $rules);

        $this->assertSame('cont_focons', $payload['legacy_db']);
        $this->assertTrue($payload['has_violations']);
        $this->assertSame(2, $payload['summary']['entities_checked']);
        $this->assertSame(3, $payload['summary']['rules_checked']);
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
}
