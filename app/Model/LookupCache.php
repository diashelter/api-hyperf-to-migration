<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class LookupCache extends Model
{
    protected ?string $table = 'lookup_cache';

    public bool $incrementing = true;

    protected string $keyType = 'int';

    public const UPDATED_AT = null;

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
