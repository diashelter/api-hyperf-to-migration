<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Confrontation extends Model
{
    protected ?string $table = 'confrontations';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'contract_id',
        'description',
        'company_id',
        'user_create_id',
        'user_finish_id',
        'user_create',
        'user_finish',
        'company_name',
        'company_cnpj',
        'status',
        'layouts',
        'entries',
        'finished_on',
        'consider_date',
        'consider_debit_credit',
        'consider_document',
        'ignore_equals',
        'finish_on_import_id',
        'selected_bank_financial',
        'selected_bank_bank',
        'consider_history',
        'is_bulk_linked',
    ];
}
