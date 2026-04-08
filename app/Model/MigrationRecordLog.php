<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class MigrationRecordLog extends Model
{
    protected ?string $table = 'migration_record_logs';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    public const UPDATED_AT = null;

    protected array $fillable = [
        'id',
        'request_id',
        'contract_id',
        'entity',
        'legacy_id',
        'new_id',
        'status',
        'error_message',
        'created_at',
    ];
}
