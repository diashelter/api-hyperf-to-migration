<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Plan extends Model
{
    protected ?string $table = 'plans';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'contract_id',
        'name',
        'account_default',
        'code',
    ];
}
