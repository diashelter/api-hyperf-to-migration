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

class Contract extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'contracts';

    protected string $keyType = 'string';

    protected array $fillable = [
        'cpf_cnpj',
        'corporate_name',
        'street',
        'number',
        'neighborhood',
        'city',
        'complement',
        'state',

        'name',
        'email',
        'contractor_type',
        'company_count',

        'status_contract',
        'zipcode',
        'is_approval',
        'phone',
        'legacy_database_id',
        'created_at',
    ];
}
