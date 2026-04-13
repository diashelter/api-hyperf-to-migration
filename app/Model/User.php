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

class User extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'users';

    protected string $keyType = 'string';

    protected array $fillable = [
        'name',
        'email',
        'password',
        'status',
        'is_admin',
        'is_internal',
        'temporary_password',
        'must_change_password',
        'password_changed_at',
        'is_support',
    ];
}
