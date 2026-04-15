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

class MigrationAuditLog extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'migration_audit_logs';

    protected string $keyType = 'string';

    protected array $fillable = [
        'id',
        'request_id',
        'contract_id',
        'entity',
        'batch_id',
        'ip_address',
        'user_agent',
        'total_received',
        'total_valid',
        'total_invalid',
        'total_inserted',
        'total_failed',
        'total_skipped',
        'status',
        'request_payload',
        'response_payload',
        'validation_errors',
        'insert_errors',
        'started_at',
        'completed_at',
        'processing_time_ms',
    ];
}
