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

namespace App\Controller\Migration;

use App\Exception\UnauthorizedException;
use App\Service\TokenService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

use function Hyperf\Support\env;

#[Controller(prefix: '/api/v1')]
#[HyperfServer('http')]
class TokenController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

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
            new OA\Response(response: 200, description: 'Token gerado com sucesso', content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse')),
            new OA\Response(response: 401, description: 'Secret inválido', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
            new OA\Response(response: 500, description: 'Erro interno do servidor', content: new OA\JsonContent(ref: '#/components/schemas/ProblemResponse')),
        ]
    )]
    #[PostMapping(path: 'token')]
    public function generate(): PsrResponseInterface
    {
        $userId = $this->request->input('user_id', '');
        $contractId = $this->request->input('contract_id');
        $secret = $this->request->input('secret', '');

        if ($secret !== env('JWT_SECRET', '')) {
            throw new UnauthorizedException('Invalid secret.');
        }

        $token = $this->tokenService->generate($userId, $contractId);

        return $this->response->json([
            'token' => $token,
            'type' => 'Bearer',
            'expires_in' => (int) env('JWT_TTL', 86400),
        ]);
    }
}
