<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Contract extends Model
{
    protected ?string $table = 'contracts';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'cpf_cnpj',
        'corporate_name',
        'street',
        'number',
        'neighborhood',
        'city',
        'complement',
        'state',
        'activity_branch',
        'name',
        'email',
        'contractor_type',
        'company_count',
        'user_count',
        'status_contract',
        'zipcode',
        'is_approval',
        'phone',
        'legacy_database_id',
    ];
}
