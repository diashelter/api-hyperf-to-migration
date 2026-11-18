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

namespace App\Service;

use GuzzleHttp\Client;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Guzzle\ClientFactory;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

use function Hyperf\Support\env;

/**
 * Lê no Manager, numa única chamada, os módulos contratados pelo cliente (Open Finance / Confronto)
 * e a quantidade de empresas contratadas, e grava no contrato migrado. Mensagens de erro nunca levam
 * secret, token, CNPJ nem trecho do corpo da resposta (que traz dados pessoais).
 */
class ContractModulesSyncService
{
    #[Inject]
    protected IdMappingService $idMappingService;

    #[Inject]
    protected ClientFactory $guzzleClientFactory;

    public function sync(string $migrationScope): void
    {
        $secret = (string) env('MANAGER_SECRET', '');

        if ($secret === '') {
            throw new RuntimeException('Manager secret is not configured.');
        }

        $contractId = $this->idMappingService->resolve('contracts', $migrationScope, $migrationScope);

        if ($contractId === null || $contractId === '') {
            throw new RuntimeException(sprintf("Contract mapping not found for migration scope '%s'.", $migrationScope));
        }

        $cpfCnpj = Db::connection('conciliador_web')
            ->table('contracts')
            ->where('id', $contractId)
            ->value('cpf_cnpj');

        if ($cpfCnpj === null) {
            throw new RuntimeException(sprintf("Contract '%s' was not found in conciliador_web.", $contractId));
        }

        $cnpj = $this->formatCnpj(preg_replace('/\D+/', '', (string) $cpfCnpj) ?? '');

        $client = $this->guzzleClientFactory->create(['timeout' => (float) env('MANAGER_API_TIMEOUT', 5.0)]);
        $baseUrl = $this->baseUrl();

        $token = $this->fetchToken($client, $baseUrl, $secret);
        $payload = $this->fetchClientPayload($client, $baseUrl, $token, $cnpj);
        $fields = $this->resolveModules($this->pickClient($payload, $cnpj));

        Db::connection('conciliador_web')
            ->table('contracts')
            ->where('id', $contractId)
            ->update($fields);

        // Os módulos já foram gravados; sem `empresas_contr` válido o company_count fica como está e o erro sobe.
        if (! array_key_exists('company_count', $fields)) {
            throw new RuntimeException("Manager API field 'empresas_contr' must be numeric.");
        }
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) env('MANAGER_API_URL', 'https://manager.conciliadorcontabil.com.br'), '/');

        if (! preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }

        return $baseUrl;
    }

    private function formatCnpj(string $digits): string
    {
        if (strlen($digits) !== 14) {
            throw new RuntimeException('Contract CNPJ must have exactly 14 digits.');
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($digits, 0, 2),
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 4),
            substr($digits, 12, 2),
        );
    }

    private function fetchToken(Client $client, string $baseUrl, string $secret): string
    {
        $payload = $this->postJson($client, $baseUrl . '/api/get-token', [
            'http_errors' => false,
            'query' => ['secret' => $secret],
        ], 'token');

        $token = is_array($payload['data'] ?? null) ? ($payload['data']['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Manager API did not return an access token.');
        }

        return $token;
    }

    /**
     * @return array<mixed>
     */
    private function fetchClientPayload(Client $client, string $baseUrl, string $token, string $cnpj): array
    {
        return $this->postJson($client, $baseUrl . '/api/clientes', [
            'http_errors' => false,
            'query' => ['cnpj' => $cnpj],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ], 'client');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function postJson(Client $client, string $url, array $options, string $step): array
    {
        try {
            /** @var ResponseInterface $response */
            $response = $client->post($url, $options);
        } catch (Throwable $e) {
            // A mensagem do Guzzle traz a URL (com o secret na query): não repassar.
            throw new RuntimeException(sprintf('Manager API %s request failed (%s).', $step, $e::class));
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf('Manager API returned HTTP %d on %s request.', $statusCode, $step));
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(sprintf('Manager API returned invalid JSON on %s request.', $step));
        }

        if (! is_array($payload)) {
            throw new RuntimeException(sprintf('Manager API returned an unexpected payload on %s request.', $step));
        }

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function pickClient(array $payload, string $cnpj): array
    {
        $apiStatus = $payload['api_status'] ?? null;
        $clients = $payload['data'] ?? null;

        if (! in_array($apiStatus, [1, '1'], true) || ! is_array($clients) || $clients === [] || ! array_is_list($clients)) {
            throw new RuntimeException('Manager API did not return the client.');
        }

        foreach ($clients as $client) {
            if (is_array($client) && ($client['cnpj'] ?? null) === $cnpj) {
                return $client;
            }
        }

        if (! is_array($clients[0])) {
            throw new RuntimeException('Manager API did not return the client.');
        }

        return $clients[0];
    }

    /**
     * @param array<mixed> $client
     * @return array{has_open_finance_module: bool, has_confrontation_module: bool, company_count?: int}
     */
    private function resolveModules(array $client): array
    {
        $belvo = $client['belvo'] ?? null;
        $confronto = $client['confronto'] ?? null;
        $companyCount = $client['empresas_contr'] ?? null;

        $fields = [
            'has_open_finance_module' => $belvo === 1 || $belvo === '1' || $belvo === true,
            'has_confrontation_module' => $confronto === null
                || (is_string($confronto) && strcasecmp(trim($confronto), 'Sim') === 0),
        ];

        if (is_int($companyCount) || (is_string($companyCount) && is_numeric($companyCount))) {
            $fields['company_count'] = (int) $companyCount;
        }

        return $fields;
    }
}
