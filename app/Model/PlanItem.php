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

class PlanItem extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'plan_items';

    protected string $keyType = 'string';

    protected array $fillable = [
        'plan_id',
        'type',
        'name',
        'complete_account',
        'reduced_account',
        'origin',
    ];
}
