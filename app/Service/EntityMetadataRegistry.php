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

namespace App\Service;

use App\PullMode\Source\AbstractLegacySource;
use App\PullMode\Source\CompanyLayoutFixedAccountSource;
use App\PullMode\Source\CompanyLayoutSource;
use App\PullMode\Source\CompanySource;
use App\PullMode\Source\ConfrontationConciliationSource;
use App\PullMode\Source\ConfrontationRecordSource;
use App\PullMode\Source\ConfrontationSource;
use App\PullMode\Source\ContractSource;
use App\PullMode\Source\ContractUserSource;
use App\PullMode\Source\IgnoredConciliationTermSource;
use App\PullMode\Source\ImportRecordSource;
use App\PullMode\Source\ImportSessionSource;
use App\PullMode\Source\ImportSource;
use App\PullMode\Source\LayoutSource;
use App\PullMode\Source\PeopleSource;
use App\PullMode\Source\PeopleVinculatedSource;
use App\PullMode\Source\PlanItemSource;
use App\PullMode\Source\PlanSource;
use App\PullMode\Source\RuleSource;
use App\PullMode\Source\RulesSharingSource;
use App\PullMode\Source\UserCompanyRestrictionSource;
use App\PullMode\Source\UserPermissionSource;
use App\PullMode\Source\UserSource;

/**
 * Lista ordenada (em ordem de FK) das classes Source que o pull-mode percorre.
 * Cada classe em `app/PullMode/Source/` é a única fonte de verdade sobre como
 * ler/transformar uma entidade legada.
 *
 * Para adicionar uma nova entidade:
 *   1. Criar `App\PullMode\Source\<Nome>Source` estendendo AbstractLegacySource.
 *   2. Adicionar o ::class abaixo na posição correta (depois de TODAS as suas
 *      dependências de FK).
 *
 * Importante: NÃO paralelizar entidades — a ordem do array importa.
 */
class EntityMetadataRegistry
{
    /**
     * @return array<int, class-string<AbstractLegacySource>>
     */
    public static function sources(): array
    {
        return [
            ContractSource::class,
            UserSource::class,
            UserPermissionSource::class,
            ContractUserSource::class,
            PlanSource::class,
            RulesSharingSource::class,
            LayoutSource::class,

            PlanItemSource::class,
            CompanySource::class,

            CompanyLayoutSource::class,
            CompanyLayoutFixedAccountSource::class,
            UserCompanyRestrictionSource::class,

            PeopleSource::class,
            PeopleVinculatedSource::class,

            ImportSource::class,
            ImportSessionSource::class,
            RuleSource::class,
            ImportRecordSource::class,

            IgnoredConciliationTermSource::class,

            ConfrontationSource::class,
            ConfrontationRecordSource::class,
            ConfrontationConciliationSource::class,
        ];
    }

    /**
     * Nomes canônicos de entidade na mesma ordem de sources(), usados para
     * reordenar entity_progress na resposta da API.
     *
     * @return array<int, string>
     */
    public static function entityNames(): array
    {
        return array_map(
            static fn(string $class): string => (new $class())->entity(),
            static::sources()
        );
    }
}
