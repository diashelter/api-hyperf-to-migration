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

use App\PullMode\Source\IgnoredConciliationTermSource;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(IgnoredConciliationTermSource::class)]
final class IgnoredConciliationTermSourceTest extends UnitTestCase
{
    public function testQueriesIgnoreRowsWithNullHistory(): void
    {
        $source = new IgnoredConciliationTermSource();

        $this->assertStringContainsString('historico IS NOT NULL', $source->countSql());
        $this->assertStringContainsString('historico IS NOT NULL', $source->sql());
    }
}
