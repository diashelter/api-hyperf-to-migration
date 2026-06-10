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

use App\PullMode\Source\PeopleVinculatedSource;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(PeopleVinculatedSource::class)]
final class PeopleVinculatedSourceTest extends UnitTestCase
{
    public function testQueriesResolvePeopleThroughCanonicalPeopleSourceCriteria(): void
    {
        $source = new PeopleVinculatedSource();
        $sql = $source->sql();

        foreach ([$sql, $source->countSql()] as $query) {
            $this->assertStringContainsString('WITH pessoas_normalized AS (', $query);
            $this->assertStringContainsString("NULLIF(REGEXP_REPLACE(COALESCE(pessoas.cpfcnpj, ''), '[^0-9]', '', 'g'), '') AS normalized_cpf", $query);
            $this->assertStringContainsString('NULLIF(TRIM(pessoas.nomerazao), \'\') AS normalized_name', $query);
            $this->assertStringContainsString('DISTINCT ON (normalized_cpf)', $query);
            $this->assertStringContainsString('DISTINCT ON (normalized_name)', $query);
            $this->assertStringContainsString('canonical_people_id', $query);
            $this->assertStringContainsString('pessoas_vinculadas.normalized_cpf IS NOT NULL', $query);
            $this->assertStringContainsString('pessoas_vinculadas.normalized_name IS NOT NULL', $query);
            $this->assertStringNotContainsString('pessoas_ref.legacy_id = pessoas_vinculo.fk_pessoa', $query);
        }

        $this->assertStringContainsString('pessoas_ref.canonical_people_id AS legacy_people_id', $sql);
    }
}
