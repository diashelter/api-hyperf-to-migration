<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class People extends Model
{
    protected ?string $table = 'peoples';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'contract_id',
        'cpf_cnpj',
        'corporate_name',
    ];
}
