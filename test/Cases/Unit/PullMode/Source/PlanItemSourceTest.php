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

use App\PullMode\Source\PlanItemSource;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(PlanItemSource::class)]
final class PlanItemSourceTest extends UnitTestCase
{
    public function testQueriesOnlyReadRowsWithExistingPlans(): void
    {
        $source = new PlanItemSource();

        $this->assertStringContainsString('INNER JOIN pcontasconc ON pcontasconc.pk = pcontasconc_item.fk_pcontasconc', $source->sql());
        $this->assertStringContainsString('pcontasconc_item.fk_pcontasconc IS NOT NULL', $source->sql());
        $this->assertStringContainsString('pcontasconc_item.fk_pcontasconc <> 0', $source->sql());
        $this->assertStringContainsString('INNER JOIN pcontasconc ON pcontasconc.pk = pcontasconc_item.fk_pcontasconc', $source->countSql());
    }

    public function testSourceDoesNotInjectContractId(): void
    {
        $source = new PlanItemSource();

        $this->assertFalse($source->hasContractId());
        $this->assertSame('legacy_id', $source->paginationKey());
    }
}
