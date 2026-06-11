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

use App\PullMode\Source\ConfrontationSource;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ConfrontationSource::class)]
final class ConfrontationSourceTest extends UnitTestCase
{
    public function testQueryAggregatesItemsBeforeJoiningFinancialAndBankSides(): void
    {
        $source = new ConfrontationSource();
        $sql = $source->sql();

        $this->assertStringContainsString('WITH scoped_confrontos AS (', $sql);
        $this->assertStringContainsString('rec_fin AS (', $sql);
        $this->assertStringContainsString('rec_ban AS (', $sql);
        $this->assertStringContainsString('COUNT(DISTINCT confrontos_itens.pk) AS entries_count', $sql);
        $this->assertStringContainsString('STRING_AGG(DISTINCT confrontos_itens.fk_layoutimp::text', $sql);
        $this->assertStringContainsString("rec_fin.layouts || ' <=> ' || rec_ban.layouts AS layouts", $sql);
        $this->assertStringContainsString("rec_fin.entries_count || ' / ' || rec_ban.entries_count AS entries", $sql);
        $this->assertStringContainsString('ORDER BY scoped_confrontos.pk ASC', $sql);

        $this->assertStringNotContainsString('JOIN confrontos_itens rec_fin', $sql);
        $this->assertStringNotContainsString('JOIN confrontos_itens rec_ban', $sql);
        $this->assertStringNotContainsString('GROUP BY 1,2,3', $sql);
    }
}
