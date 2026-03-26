<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class MigrationBatch extends Model
{
    protected ?string $table = 'migration_batches';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'id',
        'contract_id',
        'entity',
        'status',
        'total_records',
        'processed_records',
        'failed_records',
        'error_details',
        'started_at',
        'completed_at',
    ];
}
