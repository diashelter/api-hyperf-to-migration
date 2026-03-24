<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Company extends Model
{
    protected ?string $table = 'companies';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'code',
        'external_code',
        'cpf_cnpj',
        'corporate_name',
        'street',
        'number',
        'neighborhood',
        'city',
        'complement',
        'state',
        'activity_branch',
        'tax_regime',
        'contract_id',
        'zipcode',
        'state_registration',
        'city_registration',
        'phone',
        'phone_cell',
        'email',
        'is_active',
        'observation',
        'use_participant',
        'use_cost_center',
        'use_auto_register_of_people',
        'use_auto_register_of_people_in_rules_accounting',
        'plan_id',
        'rules_sharing_id',
        'dont_ask_replace_previous_import',
    ];
}
