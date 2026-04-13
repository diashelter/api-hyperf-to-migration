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

class ImportRecord extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'import_records';

    protected string $keyType = 'string';

    protected array $fillable = [
        'id',
        'import_session_id',
        'import_id',
        'num_doc',
        'date',
        'history',
        'value',
        'client_supplier',
        'debit_credit',
        'bank',
        'refund_values',
        'due_date',
        'third_party_participant',
        'additional_information',
        'complement',
        'debit_account',
        'credit_account',
        'filial',
        'parcel',
        'checked',
        'is_conciliated',
        'is_manual',
        'accounting_history',
        'cpf_cnpj',
        'additional_information_2',
        'additional_information_3',
        'created_at',
        'dismembered_values_id',
        'order_number',
        'cc_debit',
        'cc_credit',
        'ignored_by_rule_id',
        'ignored_at',
        'not_considered',
        'was_exported',
        'is_split',
        'history_code',
        'new_history',
        'participant_debit',
        'participant_credit',
        'selected',
        'automatic_launch',
        'conciliation_status',
        'id_rule',
        'is_from_confrontation',
        'is_leftover_from_confrontation',
        'was_entered_manually',
        'pis_value',
        'cofins_value',
        'csll_value',
        'irrf_value',
        'pis_cosirf_value',
        'cofins_cosirf_value',
        'csll_cosirf_value',
        'irpj_cosirf_value',
        'irrfp_value',
    ];
}
