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

/**
 * Resultado de um seed do lookup_cache: total upsertado e o que mudou em relação ao cache.
 */
final class LookupSeedResult
{
    public function __construct(
        public readonly string $entity,
        public readonly int $total,
        public readonly int $inserted,
        public readonly int $updated,
        public readonly int $removed,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->inserted + $this->updated + $this->removed > 0;
    }
}
