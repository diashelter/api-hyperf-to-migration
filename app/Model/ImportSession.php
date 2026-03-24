<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class ImportSession extends Model
{
    protected ?string $table = 'import_sessions';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'import_id',
        'layout_id',
        'status',
        'original_file_name',
        'file_name',
        'size',
        'date_to_create',
        'converter_id',
        'current_page',
    ];
}
