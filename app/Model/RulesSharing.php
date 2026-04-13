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

class RulesSharing extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'rules_sharings';

    protected string $keyType = 'string';

    protected array $fillable = [
        'code',
        'name',
        'contract_id',
    ];
}
