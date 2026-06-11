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

use App\PullMode\Source\RuleSource;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(RuleSource::class)]
final class RuleSourceTest extends UnitTestCase
{
    public function testQueriesOnlyReadRulesWithMigratedLayouts(): void
    {
        $source = new RuleSource();

        foreach ([$source->sql(), $source->countSql()] as $query) {
            $this->assertStringContainsString(
                'JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1 AND layout.tipo = \'IMP\'',
                $query
            );
            $this->assertStringContainsString('WHERE fk_layoutimp <> 0', $query);
        }

        $this->assertStringContainsString('regras.historico', $source->sql());
        $this->assertStringContainsString('regras.cd', $source->sql());
        $this->assertStringContainsString('REGEXP_REPLACE(regras.cpfcnpj', $source->sql());
    }
}
