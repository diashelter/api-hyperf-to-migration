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

use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Guzzle\ClientFactory;
use JsonException;
use RuntimeException;
use Throwable;

use function Hyperf\Support\env;

class ContractCompanyCountSyncService
{
    #[Inject]
    protected IdMappingService $idMappingService;

    #[Inject]
    protected ClientFactory $guzzleClientFactory;

    public function sync(string $migrationScope): void
    {
        $contractId = $this->idMappingService->resolve('contracts', $migrationScope, $migrationScope);

        if ($contractId === null || $contractId === '') {
            throw new RuntimeException(sprintf("Contract mapping not found for migration scope '%s'.", $migrationScope));
        }

        $cnpj = Db::connection('conciliador_web')
            ->table('contracts')
            ->where('id', $contractId)
            ->value('cpf_cnpj');

        if ($cnpj === null) {
            throw new RuntimeException(sprintf("Contract '%s' was not found in conciliador_web.", $contractId));
        }

        $cnpj = preg_replace('/\D+/', '', (string) $cnpj) ?? '';

        if ($cnpj === '') {
            throw new RuntimeException(sprintf("Contract '%s' does not have a valid CNPJ.", $contractId));
        }

        $companyCount = $this->fetchCompanyCount($cnpj);

        Db::connection('conciliador_web')
            ->table('contracts')
            ->where('id', $contractId)
            ->update(['company_count' => $companyCount]);
    }

    private function fetchCompanyCount(string $cnpj): int
    {
        $baseUrl = rtrim((string) env('MANAGER_API_BASE_URL', 'https://managerv2api.conciliadorcontabil.com.br'), '/');
        if (! preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }
        $timeout = (float) env('MANAGER_API_TIMEOUT', 5.0);
        $url = $baseUrl . '/api/public/clientes/cnpj';

        try {
            $client = $this->guzzleClientFactory->create(['timeout' => $timeout]);
            $response = $client->get($url, [
                'http_errors' => false,
                'query' => ['cnpj' => $cnpj],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Manager API request failed: ' . $e->getMessage(), 0, $e);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf('Manager API returned HTTP %d while fetching company_count.', $statusCode));
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Manager API returned invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (! is_array($payload) || ! array_key_exists('empresas_contr', $payload)) {
            throw new RuntimeException("Manager API response does not contain 'empresas_contr'.");
        }

        $companyCount = $payload['empresas_contr'];

        if ($companyCount === null || ! is_numeric($companyCount)) {
            throw new RuntimeException("Manager API field 'empresas_contr' must be numeric.");
        }

        return (int) $companyCount;
    }
}
