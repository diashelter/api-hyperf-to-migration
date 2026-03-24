<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class PlanItem extends Model
{
    protected ?string $table = 'plan_items';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'plan_id',
        'type',
        'name',
        'complete_account',
        'reduced_account',
        'origin',
    ];
}
