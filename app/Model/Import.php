<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Import extends Model
{
    protected ?string $table = 'imports';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'name',
        'user_id',
        'company_id',
        'initial_period',
        'final_period',
        'status',
        'conciliation_status',
        'total_files',
        'error_message',
        'contract_id',
        'company_layout_id',
        'previous_balance',
        'is_big_import',
    ];
}
