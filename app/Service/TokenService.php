<?php

declare(strict_types=1);

namespace App\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use function Hyperf\Support\env;

class TokenService
{
    public function generate(string $userId, ?string $contractId = null): string
    {
        $secret = env('JWT_SECRET', '');
        $ttl = (int) env('JWT_TTL', 86400);

        $payload = [
            'sub' => $userId,
            'contract_id' => $contractId,
            'iat' => time(),
            'exp' => time() + $ttl,
            'abilities' => ['migration:write', 'migration:read'],
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    public function decode(string $token): object
    {
        $secret = env('JWT_SECRET', '');
        return JWT::decode($token, new Key($secret, 'HS256'));
    }
}
