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

namespace HyperfTest\Cases\Unit\Service;

use App\Service\ContractModulesSyncService;
use App\Service\IdMappingService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hyperf\Guzzle\ClientFactory;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(ContractModulesSyncService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContractModulesSyncServiceTest extends UnitTestCase
{
    private const SCOPE = 'cont_ficticio';

    private const CONTRACT_ID = 'contract-uuid';

    private const CNPJ_DIGITS = '11222333000181';

    private const CNPJ_FORMATTED = '11.222.333/0001-81';

    private const SECRET = 'secret-fake';

    private const TOKEN = 'token-fake';

    private const EMAIL = 'fulano@example.test';

    private const PHONE = '11999990000';

    private const COMPANY_COUNT = 62;

    /**
     * Chamadas feitas ao Client mockado: lista de [url, options].
     *
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvValue('MANAGER_SECRET', self::SECRET);
        $this->setEnvValue('MANAGER_API_URL', 'https://manager.example.test');
        $this->setEnvValue('MANAGER_API_TIMEOUT', null);
    }

    public function testSyncEnablesBothModulesAndCallsManagerWithFormattedCnpjAndBearerToken(): void
    {
        $this->setEnvValue('MANAGER_API_URL', 'manager.example.test/');
        $this->setEnvValue('MANAGER_API_TIMEOUT', '2.5');

        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$this->client(['belvo' => 1, 'confronto' => 'Sim'])])],
            ['has_open_finance_module' => true, 'has_confrontation_module' => true, 'company_count' => self::COMPANY_COUNT],
            timeout: 2.5,
        );

        $service->sync(self::SCOPE);

        $this->assertCount(2, $this->calls);

        [$tokenUrl, $tokenOptions] = $this->calls[0];
        $this->assertSame('https://manager.example.test/api/get-token', $tokenUrl);
        $this->assertSame(['secret' => self::SECRET], $tokenOptions['query']);
        $this->assertFalse($tokenOptions['http_errors']);

        [$clientsUrl, $clientsOptions] = $this->calls[1];
        $this->assertSame('https://manager.example.test/api/clientes', $clientsUrl);
        $this->assertSame(['cnpj' => self::CNPJ_FORMATTED], $clientsOptions['query']);
        $this->assertSame('Bearer ' . self::TOKEN, $clientsOptions['headers']['Authorization']);
        $this->assertSame('application/json', $clientsOptions['headers']['Accept']);
        $this->assertFalse($clientsOptions['http_errors']);
    }

    public function testSyncDisablesBothModulesWhenClientDoesNotHaveThem(): void
    {
        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$this->client(['belvo' => 0, 'confronto' => 'Não'])])],
            ['has_open_finance_module' => false, 'has_confrontation_module' => false, 'company_count' => self::COMPANY_COUNT],
        );

        $service->sync(self::SCOPE);
        $this->assertCount(2, $this->calls);
    }

    #[DataProvider('truthyModuleValuesProvider')]
    public function testSyncAcceptsAlternativeTruthyModuleValues(mixed $belvo, mixed $confronto): void
    {
        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$this->client(['belvo' => $belvo, 'confronto' => $confronto])])],
            ['has_open_finance_module' => true, 'has_confrontation_module' => true, 'company_count' => self::COMPANY_COUNT],
        );

        $service->sync(self::SCOPE);
        $this->assertCount(2, $this->calls);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function truthyModuleValuesProvider(): array
    {
        return [
            'belvo string 1 e confronto minúsculo' => ['1', 'sim'],
            'belvo true e confronto com espaços' => [true, ' Sim '],
        ];
    }

    #[DataProvider('missingConfrontoProvider')]
    public function testSyncKeepsConfrontationEnabledWhenConfrontoIsMissingOrNull(array $fields): void
    {
        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$this->client($fields)])],
            ['has_open_finance_module' => true, 'has_confrontation_module' => true, 'company_count' => self::COMPANY_COUNT],
        );

        $service->sync(self::SCOPE);
        $this->assertCount(2, $this->calls);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function missingConfrontoProvider(): array
    {
        return [
            'sem confronto' => [['belvo' => 1]],
            'confronto null' => [['belvo' => 1, 'confronto' => null]],
        ];
    }

    public function testSyncDisablesOpenFinanceWhenBelvoIsMissing(): void
    {
        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$this->client(['confronto' => 'Não'])])],
            ['has_open_finance_module' => false, 'has_confrontation_module' => false, 'company_count' => self::COMPANY_COUNT],
        );

        $service->sync(self::SCOPE);
        $this->assertCount(2, $this->calls);
    }

    public function testSyncUsesClientWhoseCnpjMatchesTheQueriedOne(): void
    {
        $other = ['cnpj' => '99.888.777/0001-66', 'belvo' => 0, 'confronto' => 'Não', 'empresas_contr' => 1, 'email' => 'outro@example.test'];
        $matching = $this->client(['belvo' => 1, 'confronto' => 'Sim']);

        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$other, $matching])],
            ['has_open_finance_module' => true, 'has_confrontation_module' => true, 'company_count' => self::COMPANY_COUNT],
        );

        $service->sync(self::SCOPE);
        $this->assertCount(2, $this->calls);
    }

    public function testSyncFallsBackToFirstClientWhenNoCnpjMatches(): void
    {
        $first = ['cnpj' => '99.888.777/0001-66', 'belvo' => 1, 'confronto' => 'Sim', 'empresas_contr' => self::COMPANY_COUNT];
        $second = ['cnpj' => '55.444.333/0001-22', 'belvo' => 0, 'confronto' => 'Não', 'empresas_contr' => 1];

        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$first, $second])],
            ['has_open_finance_module' => true, 'has_confrontation_module' => true, 'company_count' => self::COMPANY_COUNT],
        );

        $service->sync(self::SCOPE);
        $this->assertCount(2, $this->calls);
    }

    public function testSyncAcceptsCompanyCountAsNumericString(): void
    {
        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$this->client(['belvo' => 1, 'confronto' => 'Sim', 'empresas_contr' => '62'])])],
            ['has_open_finance_module' => true, 'has_confrontation_module' => true, 'company_count' => 62],
        );

        $service->sync(self::SCOPE);
        $this->assertCount(2, $this->calls);
    }

    /**
     * Os módulos são gravados mesmo assim; o company_count fica como está e o erro sobe para o error_summary.
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('invalidCompanyCountProvider')]
    public function testSyncUpdatesModulesButFailsWhenCompanyCountIsInvalid(array $fields): void
    {
        $client = $this->client(['belvo' => 1, 'confronto' => 'Sim']);
        unset($client['empresas_contr']);

        $service = $this->createService(
            [$this->tokenResponse(), $this->clientsResponse([$client + $fields])],
            ['has_open_finance_module' => true, 'has_confrontation_module' => true],
        );

        $this->assertSyncFailsWith($service, "Manager API field 'empresas_contr' must be numeric.");
        $this->assertCount(2, $this->calls);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidCompanyCountProvider(): array
    {
        return [
            'sem empresas_contr' => [[]],
            'empresas_contr null' => [['empresas_contr' => null]],
            'empresas_contr texto' => [['empresas_contr' => 'muitas']],
        ];
    }

    #[DataProvider('clientNotReturnedProvider')]
    public function testSyncFailsWithoutUpdatingWhenManagerDoesNotReturnTheClient(string $body): void
    {
        $service = $this->createService([$this->tokenResponse(), new Response(200, [], $body)], null);

        $this->assertSyncFailsWith($service, 'Manager API did not return the client.');
        $this->assertCount(2, $this->calls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function clientNotReturnedProvider(): array
    {
        $client = [
            'cnpj' => self::CNPJ_FORMATTED,
            'belvo' => 1,
            'confronto' => 'Sim',
            'email' => self::EMAIL,
            'telefone' => self::PHONE,
        ];

        return [
            'api_status 0' => [json_encode(['api_status' => 0, 'data' => [$client]])],
            'data vazio' => [json_encode(['api_status' => 1, 'data' => []])],
            'sem data' => [json_encode(['api_status' => 1, 'email' => self::EMAIL])],
            'data objeto' => [json_encode(['api_status' => 1, 'data' => $client])],
        ];
    }

    #[DataProvider('emptySecretProvider')]
    public function testSyncFailsBeforeAnyCallWhenSecretIsNotConfigured(?string $secret): void
    {
        $this->setEnvValue('MANAGER_SECRET', $secret);

        $idMappingService = Mockery::mock(IdMappingService::class);
        $idMappingService->shouldNotReceive('resolve');

        $clientFactory = Mockery::mock(ClientFactory::class);
        $clientFactory->shouldNotReceive('create');

        $service = new ContractModulesSyncService();
        $this->injectProperty($service, 'idMappingService', $idMappingService);
        $this->injectProperty($service, 'guzzleClientFactory', $clientFactory);

        $this->assertSyncFailsWith($service, 'Manager secret is not configured.');
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function emptySecretProvider(): array
    {
        return [
            'secret ausente' => [null],
            'secret vazio' => [''],
        ];
    }

    #[DataProvider('tokenFailureProvider')]
    public function testSyncFailsWithoutUpdatingWhenTokenIsNotObtained(int $status, string $body, string $expectedMessage): void
    {
        $service = $this->createService([new Response($status, [], $body)], null);

        $this->assertSyncFailsWith($service, $expectedMessage);
        $this->assertCount(1, $this->calls);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function tokenFailureProvider(): array
    {
        return [
            'sem access_token' => [
                200,
                json_encode(['api_status' => 1, 'data' => []]),
                'Manager API did not return an access token.',
            ],
            'access_token vazio' => [
                200,
                json_encode(['data' => ['access_token' => '']]),
                'Manager API did not return an access token.',
            ],
            'HTTP 401' => [
                401,
                json_encode(['message' => 'secret ' . self::SECRET . ' inválido']),
                'Manager API returned HTTP 401 on token request.',
            ],
            'JSON inválido' => [
                200,
                '<html>' . self::SECRET . '</html>',
                'Manager API returned invalid JSON on token request.',
            ],
        ];
    }

    #[DataProvider('clientsFailureProvider')]
    public function testSyncFailsWithoutUpdatingWhenClientRequestFails(int $status, string $body, string $expectedMessage): void
    {
        $service = $this->createService([$this->tokenResponse(), new Response($status, [], $body)], null);

        $this->assertSyncFailsWith($service, $expectedMessage);
        $this->assertCount(2, $this->calls);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function clientsFailureProvider(): array
    {
        $personalData = json_encode(['email' => self::EMAIL, 'telefone' => self::PHONE, 'cnpj' => self::CNPJ_FORMATTED]);

        return [
            'HTTP 404' => [404, $personalData, 'Manager API returned HTTP 404 on client request.'],
            'HTTP 500' => [500, $personalData, 'Manager API returned HTTP 500 on client request.'],
            'JSON inválido' => [
                200,
                '{"email":"' . self::EMAIL . '","telefone":"' . self::PHONE,
                'Manager API returned invalid JSON on client request.',
            ],
        ];
    }

    public function testSyncHidesGuzzleMessageWhenTokenRequestCannotConnect(): void
    {
        $url = 'https://manager.example.test/api/get-token?secret=' . self::SECRET;
        $exception = new ConnectException('cURL error 28: timeout for ' . $url, new Request('POST', $url));

        $service = $this->createService([$exception], null);

        $this->assertSyncFailsWith(
            $service,
            'Manager API token request failed (GuzzleHttp\Exception\ConnectException).',
        );
        $this->assertCount(1, $this->calls);
    }

    public function testSyncHidesGuzzleMessageWhenClientRequestCannotConnect(): void
    {
        $url = 'https://manager.example.test/api/clientes?cnpj=' . urlencode(self::CNPJ_FORMATTED);
        $exception = new ConnectException(
            'cURL error 28: timeout for ' . $url . ' Bearer ' . self::TOKEN,
            new Request('POST', $url),
        );

        $service = $this->createService([$this->tokenResponse(), $exception], null);

        $this->assertSyncFailsWith(
            $service,
            'Manager API client request failed (GuzzleHttp\Exception\ConnectException).',
        );
        $this->assertCount(2, $this->calls);
    }

    public function testSyncFailsWhenContractMappingDoesNotExist(): void
    {
        $idMappingService = Mockery::mock(IdMappingService::class);
        $idMappingService->shouldReceive('resolve')
            ->once()
            ->with('contracts', self::SCOPE, self::SCOPE)
            ->andReturn(null);

        $clientFactory = Mockery::mock(ClientFactory::class);
        $clientFactory->shouldNotReceive('create');

        $service = new ContractModulesSyncService();
        $this->injectProperty($service, 'idMappingService', $idMappingService);
        $this->injectProperty($service, 'guzzleClientFactory', $clientFactory);

        $this->assertSyncFailsWith($service, "Contract mapping not found for migration scope 'cont_ficticio'.");
    }

    public function testSyncFailsWhenContractHasNoCnpj(): void
    {
        $service = $this->createService([], null, cpfCnpj: null);

        $this->assertSyncFailsWith($service, "Contract 'contract-uuid' was not found in conciliador_web.");
        $this->assertCount(0, $this->calls);
    }

    public function testSyncFailsWhenContractCnpjDoesNotHaveFourteenDigits(): void
    {
        $service = $this->createService([], null, cpfCnpj: '123.456.789-09');

        $this->assertSyncFailsWith($service, 'Contract CNPJ must have exactly 14 digits.');
        $this->assertCount(0, $this->calls);
    }

    /**
     * @param list<Response|Throwable> $responses respostas do Client na ordem das chamadas
     * @param null|array<string, bool|int> $expectedUpdate null = update nunca chamado
     */
    private function createService(
        array $responses,
        ?array $expectedUpdate,
        ?string $cpfCnpj = self::CNPJ_DIGITS,
        float $timeout = 5.0,
    ): ContractModulesSyncService {
        $idMappingService = Mockery::mock(IdMappingService::class);
        $idMappingService->shouldReceive('resolve')
            ->once()
            ->with('contracts', self::SCOPE, self::SCOPE)
            ->andReturn(self::CONTRACT_ID);

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();
        $builder = Mockery::mock();

        $db->shouldReceive('connection')->with('conciliador_web')->andReturn($connection);
        $connection->shouldReceive('table')->with('contracts')->andReturn($builder);
        $builder->shouldReceive('where')->with('id', self::CONTRACT_ID)->andReturnSelf();
        $builder->shouldReceive('value')->once()->with('cpf_cnpj')->andReturn($cpfCnpj);

        if ($expectedUpdate === null) {
            $builder->shouldNotReceive('update');
        } else {
            $builder->shouldReceive('update')->once()->with($expectedUpdate)->andReturn(1);
        }

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->andReturnUsing(function (string $url, array $options) use (&$responses): Response {
                $this->calls[] = [$url, $options];
                $response = array_shift($responses);

                if ($response instanceof Throwable) {
                    throw $response;
                }

                $this->assertInstanceOf(Response::class, $response, 'Unexpected extra call to the Manager API.');

                return $response;
            });

        $clientFactory = Mockery::mock(ClientFactory::class);
        $clientFactory->shouldReceive('create')
            ->with(['timeout' => $timeout])
            ->andReturn($client);

        $service = new ContractModulesSyncService();
        $this->injectProperty($service, 'idMappingService', $idMappingService);
        $this->injectProperty($service, 'guzzleClientFactory', $clientFactory);

        return $service;
    }

    /**
     * Falha esperada com a mensagem exata, sem nenhum secret, token, CNPJ ou dado pessoal (CA8).
     */
    private function assertSyncFailsWith(ContractModulesSyncService $service, string $expectedMessage): void
    {
        try {
            $service->sync(self::SCOPE);
        } catch (RuntimeException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());

            foreach ([self::SECRET, self::TOKEN, self::EMAIL, self::PHONE, self::CNPJ_FORMATTED, self::CNPJ_DIGITS] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $e->getMessage());
            }

            $this->assertNull($e->getPrevious(), 'A exceção original (com URL/secret) não deve ser encadeada.');

            return;
        }

        $this->fail('RuntimeException was not thrown.');
    }

    private function tokenResponse(): Response
    {
        return new Response(200, [], json_encode(['api_status' => 1, 'data' => ['access_token' => self::TOKEN]]));
    }

    /**
     * @param list<array<string, mixed>> $clients
     */
    private function clientsResponse(array $clients): Response
    {
        return new Response(200, [], json_encode(['api_status' => 1, 'data' => $clients]));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function client(array $fields): array
    {
        return ['cnpj' => self::CNPJ_FORMATTED, 'email' => self::EMAIL, 'telefone' => self::PHONE, 'empresas_contr' => self::COMPANY_COUNT]
            + $fields;
    }
}
