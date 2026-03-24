<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class RulesSharing extends Model
{
    protected ?string $table = 'rules_sharings';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'code',
        'name',
        'contract_id',
    ];
}
