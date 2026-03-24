<?php

declare(strict_types=1);

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Support\env;

class ApiTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly HttpResponse $response
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader) || ! str_starts_with($authHeader, 'Bearer ')) {
            return $this->response->json([
                'error' => 'Unauthorized',
                'message' => 'Missing or invalid Authorization header',
            ])->withStatus(401);
        }

        $token = substr($authHeader, 7);

        try {
            $secret = env('JWT_SECRET', '');
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            $contractId = $request->getHeaderLine('X-Contract-Id');

            if (! empty($decoded->contract_id) && $decoded->contract_id !== $contractId) {
                return $this->response->json([
                    'error' => 'Forbidden',
                    'message' => 'Token contract_id does not match X-Contract-Id header',
                ])->withStatus(403);
            }

            $request = $request
                ->withAttribute('user_id', $decoded->sub ?? null)
                ->withAttribute('contract_id', $contractId ?: ($decoded->contract_id ?? null));

            return $handler->handle($request);
        } catch (\Throwable $e) {
            return $this->response->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid token: ' . $e->getMessage(),
            ])->withStatus(401);
        }
    }
}
