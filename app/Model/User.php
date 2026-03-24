<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class User extends Model
{
    protected ?string $table = 'users';

    protected string $keyType = 'string';

    public bool $incrementing = false;

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
