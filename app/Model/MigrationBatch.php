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

class MigrationBatch extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'migration_batches';

    protected string $keyType = 'string';

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
