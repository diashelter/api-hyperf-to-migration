<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Export extends Model
{
    protected ?string $table = 'exports';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'contract_id',
        'import_id',
        'user_id',
        'company_id',
        'name',
        'config',
        'status',
        'external_id',
        'external_response',
        'file_name',
        'total_records',
        'processed_records',
        'error_message',
        'started_at',
        'completed_at',
        'download_count',
        'file_expiry_date',
        'is_active',
    ];

    protected array $casts = [
        'file_name' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'download_count' => 'integer',
        'is_active' => 'boolean',
    ];
}
