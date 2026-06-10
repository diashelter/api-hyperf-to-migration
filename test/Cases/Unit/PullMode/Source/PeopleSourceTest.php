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

use App\PullMode\Source\PeopleSource;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(PeopleSource::class)]
final class PeopleSourceTest extends UnitTestCase
{
    public function testQueriesDeduplicateCpfAndNullCpfBranchesSeparately(): void
    {
        $source = new PeopleSource();

        foreach ([$source->sql(), $source->countSql()] as $query) {
            $this->assertStringContainsString('WITH pessoas_normalized AS (', $query);
            $this->assertStringContainsString("NULLIF(REGEXP_REPLACE(COALESCE(pessoas.cpfcnpj, ''), '[^0-9]', '', 'g'), '') AS normalized_cpf", $query);
            $this->assertStringContainsString("LOWER(NULLIF(TRIM(pessoas.nomerazao), '')) AS normalized_name_key", $query);
            $this->assertStringContainsString('DISTINCT ON (normalized_cpf)', $query);
            $this->assertStringContainsString('DISTINCT ON (normalized_name_key)', $query);
            $this->assertStringContainsString('normalized_cpf IS NULL', $query);
            $this->assertStringContainsString('normalized_name_key IS NOT NULL', $query);
            $this->assertStringContainsString('ORDER BY normalized_name_key, id', $query);
        }
    }
}
