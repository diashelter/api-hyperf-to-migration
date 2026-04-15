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

class MigrationRecordLog extends Model
{
    public const UPDATED_AT = null;

    public bool $incrementing = false;

    protected ?string $table = 'migration_record_logs';

    protected string $keyType = 'string';

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
