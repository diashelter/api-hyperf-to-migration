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

class LookupCache extends Model
{
    public const UPDATED_AT = null;

    public bool $incrementing = true;

    protected ?string $table = 'lookup_cache';

    protected string $keyType = 'int';

    protected array $fillable = [
        'entity',
        'external_id',
        'label',
        'payload',
        'environment',
    ];

    protected array $casts = [
        'payload' => 'array',
    ];
}
