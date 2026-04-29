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

class MigrationJob extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'migration_jobs';

    protected string $keyType = 'string';

    protected array $fillable = [
        'id',
        'legacy_db',
        'contract_id',
        'status',
        'current_entity',
        'entity_progress',
        'totals',
        'error_summary',
        'started_at',
        'finished_at',
    ];

    protected array $casts = [
        'entity_progress' => 'array',
        'totals' => 'array',
        'error_summary' => 'array',
    ];
}
