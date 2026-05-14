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

namespace HyperfTest\Cases\Unit\PullMode\Source;

use App\PullMode\Source\ContractSource;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ContractSource::class)]
final class ContractSourceTest extends UnitTestCase
{
    public function testTransformRowUsesMigrationScopeAsCanonicalLegacyDatabaseId(): void
    {
        $row = [
            'legacy_id' => 'wrong_current_database',
            'legacy_database_id' => 'wrong_current_database',
            'legacy_status_contract' => null,
            'is_approval' => false,
        ];

        $transformed = (new ContractSource())->transformRow($row, 'cont_acessus');

        $this->assertSame('cont_acessus', $transformed['legacy_id']);
        $this->assertSame('cont_acessus', $transformed['legacy_database_id']);
        $this->assertArrayNotHasKey('legacy_status_contract', $transformed);
    }
}
