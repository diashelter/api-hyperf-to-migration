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

class ImportSession extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'import_sessions';

    protected string $keyType = 'string';

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
