<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class ConfrontationRecord extends Model
{
    protected ?string $table = 'confrontation_records';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'id',
        'selected',
        'conciliated',
        'confrontation_id',
        'order_number',
        'rule_id',
        'date',
        'layout_code',
        'num_doc',
        'debit_credit',
        'value',
        'history',
        'client_supplier',
        'bank',
        'filial',
        'parcel',
        'cpf_cnpj',
        'third_party_participant',
        'additional_information',
        'additional_information_2',
        'additional_information_3',
        'complement',
        'records_origin',
        'created_at',
        'dismembered_confrontation_id',
        'import_record_id',
        'conciliated_value',
        'import_id',
    ];
}
