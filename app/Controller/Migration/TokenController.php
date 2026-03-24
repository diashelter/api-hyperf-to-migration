<?php

declare(strict_types=1);

namespace App\Controller\Migration;

use App\Service\TokenService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use OpenApi\Attributes as OA;

use function Hyperf\Support\env;

#[Controller(prefix: '/api/v1')]
class TokenController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected TokenService $tokenService;

    #[OA\Post(
        path: '/api/v1/token',
        summary: 'Gerar token JWT',
        description: 'Gera um token JWT para autenticação na API de migração. O token deve ser enviado no header Authorization: Bearer {token} em todas as requisições protegidas.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/TokenRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token gerado com sucesso',
                content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Secret inválido',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ]
    )]
    #[PostMapping(path: 'token')]
    public function generate(): array
    {
        $userId = $this->request->input('user_id', '');
        $contractId = $this->request->input('contract_id');
        $secret = $this->request->input('secret', '');

        // Simple secret validation (in production, validate against DB)
        if ($secret !== env('JWT_SECRET', '')) {
            return ['error' => 'Invalid secret', 'code' => 401];
        }

        $token = $this->tokenService->generate($userId, $contractId);

        return [
            'token' => $token,
            'type' => 'Bearer',
            'expires_in' => (int) env('JWT_TTL', 86400),
        ];
    }
}
