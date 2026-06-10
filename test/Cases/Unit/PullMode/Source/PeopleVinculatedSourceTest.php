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
            $this->assertStringContainsString("LOWER(NULLIF(TRIM(pessoas.nomerazao), '')) AS normalized_name_key", $query);
            $this->assertStringContainsString('DISTINCT ON (normalized_cpf)', $query);
            $this->assertStringContainsString('DISTINCT ON (normalized_name_key)', $query);
            $this->assertStringContainsString('canonical_people_id', $query);
            $this->assertStringContainsString('pessoas_vinculadas.normalized_cpf IS NOT NULL', $query);
            $this->assertStringContainsString('pessoas_vinculadas.normalized_name_key IS NOT NULL', $query);
            $this->assertStringContainsString('pessoas_ref.normalized_name_key = pessoas_vinculadas.normalized_name_key', $query);
            $this->assertStringNotContainsString('pessoas_ref.legacy_id = pessoas_vinculo.fk_pessoa', $query);
        }

        $this->assertStringContainsString('pessoas_ref.canonical_people_id AS legacy_people_id', $sql);
    }

    public function testQueriesFilterInvalidCompanyOrRulesSharingScope(): void
    {
        $source = new PeopleVinculatedSource();

        foreach ([$source->sql(), $source->countSql()] as $query) {
            $this->assertStringContainsString('COALESCE(pessoas_vinculo.fk_empresa, 0) <> 0', $query);
            $this->assertStringContainsString('COALESCE(pessoas_vinculo.fk_compartilhamento, 0) = 0', $query);
            $this->assertStringContainsString('FROM empresas', $query);
            $this->assertStringContainsString('empresas.pk = pessoas_vinculo.fk_empresa', $query);
            $this->assertStringContainsString('COALESCE(pessoas_vinculo.fk_empresa, 0) = 0', $query);
            $this->assertStringContainsString('COALESCE(pessoas_vinculo.fk_compartilhamento, 0) <> 0', $query);
            $this->assertStringContainsString('FROM plano_contas', $query);
            $this->assertStringContainsString('plano_contas.pk = pessoas_vinculo.fk_compartilhamento', $query);
        }

        $this->assertStringContainsString('NULLIF(fk_empresa, 0) AS legacy_company_id', $source->sql());
        $this->assertStringContainsString('NULLIF(fk_compartilhamento, 0) AS legacy_rules_sharing_id', $source->sql());
    }
}
