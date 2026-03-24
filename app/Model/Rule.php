<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Rule extends Model
{
    protected ?string $table = 'rules';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = [
        'company_id',
        'layout_id',
        'debit_credit',
        'cpf_cnpj',
        'client_supplier',
        'history',
        'bank',
        'filial',
        'additional_information',
        'additional_information_2',
        'additional_information_3',
        'token',
        'exclusive',
        'id_history',
        'id_debit',
        'id_credit',
        'id_history_exp',
        'id_participant_credit',
        'id_participant_debit',
        'id_cc_credit',
        'id_cc_debit',
        'reprocess',
        'invalid',
        'contract_id',
        'sort_order',
        'history_value',
        'cpf_cnpj_value',
        'client_supplier_value',
        'bank_value',
        'filial_value',
        'additional_information_value',
        'additional_information_2_value',
        'additional_information_3_value',
        'history_persisted',
        'cpf_cnpj_persisted',
        'client_supplier_persisted',
        'bank_persisted',
        'filial_persisted',
        'additional_information_persisted',
        'additional_information_2_persisted',
        'additional_information_3_persisted',
        'rule_extra',
        'automatic_launch',
        'third_party_participant',
    ];
}
