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

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class MigrationIdMapping extends Model
{
    public const UPDATED_AT = null;

    public bool $incrementing = false;

    protected ?string $table = 'migration_id_mappings';

    protected string $keyType = 'string';

    protected array $fillable = [
        'migration_batch_id',
        'entity',
        'legacy_id',
        'new_id',
        'contract_id',
    ];
}
