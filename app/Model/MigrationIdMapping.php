<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class MigrationIdMapping extends Model
{
    protected ?string $table = 'migration_id_mappings';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    public const UPDATED_AT = null;

    protected array $fillable = [
        'migration_batch_id',
        'entity',
        'legacy_id',
        'new_id',
        'contract_id',
    ];
}
