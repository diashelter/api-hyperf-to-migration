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

use App\Service\ContractCompanyCountSyncService;
use App\Service\IdMappingService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hyperf\Guzzle\ClientFactory;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(ContractCompanyCountSyncService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContractCompanyCountSyncServiceTest extends UnitTestCase
{
    public function testSyncFetchesCompanyCountAndUpdatesContract(): void
    {
        $this->setEnvValue('MANAGER_API_BASE_URL', 'manager.example.test');
        $this->setEnvValue('MANAGER_API_TIMEOUT', '2.5');

        $idMappingService = Mockery::mock(IdMappingService::class);
        $idMappingService->shouldReceive('resolve')
            ->once()
            ->with('contracts', 'cont_acessus', 'cont_acessus')
            ->andReturn('contract-uuid');

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();
        $readBuilder = Mockery::mock();
        $updateBuilder = Mockery::mock();

        $db->shouldReceive('connection')
            ->twice()
            ->with('conciliador_web')
            ->andReturn($connection);
        $connection->shouldReceive('table')
            ->once()
            ->with('contracts')
            ->andReturn($readBuilder);
        $readBuilder->shouldReceive('where')
            ->once()
            ->with('id', 'contract-uuid')
            ->andReturnSelf();
        $readBuilder->shouldReceive('value')
            ->once()
            ->with('cpf_cnpj')
            ->andReturn('12.345.678/0001-90');

        $connection->shouldReceive('table')
            ->once()
            ->with('contracts')
            ->andReturn($updateBuilder);
        $updateBuilder->shouldReceive('where')
            ->once()
            ->with('id', 'contract-uuid')
            ->andReturnSelf();
        $updateBuilder->shouldReceive('update')
            ->once()
            ->with(['company_count' => 10])
            ->andReturn(1);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->with(
                'https://manager.example.test/api/public/clientes/cnpj',
                [
                    'http_errors' => false,
                    'query' => ['cnpj' => '12345678000190'],
                ]
            )
            ->andReturn(new Response(200, [], '{"empresas_contr":10}'));

        $clientFactory = Mockery::mock(ClientFactory::class);
        $clientFactory->shouldReceive('create')
            ->once()
            ->with(['timeout' => 2.5])
            ->andReturn($client);

        $service = new ContractCompanyCountSyncService();
        $this->injectProperty($service, 'idMappingService', $idMappingService);
        $this->injectProperty($service, 'guzzleClientFactory', $clientFactory);

        $service->sync('cont_acessus');
        $this->addToAssertionCount(1);
    }

    public function testSyncFailsWhenManagerApiReturnsNotFound(): void
    {
        [$service, $client] = $this->createServiceWithReadableContract();

        $client->shouldReceive('get')
            ->once()
            ->andReturn(new Response(404, [], '{"message":"Cliente não encontrado"}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Manager API returned HTTP 404');

        $service->sync('cont_acessus');
    }

    public function testSyncFailsWhenCompanyCountIsNull(): void
    {
        [$service, $client] = $this->createServiceWithReadableContract();

        $client->shouldReceive('get')
            ->once()
            ->andReturn(new Response(200, [], '{"empresas_contr":null}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Manager API field 'empresas_contr' must be numeric.");

        $service->sync('cont_acessus');
    }

    public function testSyncFailsWhenContractMappingDoesNotExist(): void
    {
        $idMappingService = Mockery::mock(IdMappingService::class);
        $idMappingService->shouldReceive('resolve')
            ->once()
            ->with('contracts', 'cont_acessus', 'cont_acessus')
            ->andReturn(null);

        $clientFactory = Mockery::mock(ClientFactory::class);
        $clientFactory->shouldNotReceive('create');

        $service = new ContractCompanyCountSyncService();
        $this->injectProperty($service, 'idMappingService', $idMappingService);
        $this->injectProperty($service, 'guzzleClientFactory', $clientFactory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Contract mapping not found for migration scope 'cont_acessus'.");

        $service->sync('cont_acessus');
    }

    public function testSyncFailsWhenDestinationContractDoesNotExist(): void
    {
        $idMappingService = Mockery::mock(IdMappingService::class);
        $idMappingService->shouldReceive('resolve')
            ->once()
            ->with('contracts', 'cont_acessus', 'cont_acessus')
            ->andReturn('contract-uuid');

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();
        $builder = Mockery::mock();

        $db->shouldReceive('connection')
            ->once()
            ->with('conciliador_web')
            ->andReturn($connection);
        $connection->shouldReceive('table')
            ->once()
            ->with('contracts')
            ->andReturn($builder);
        $builder->shouldReceive('where')
            ->once()
            ->with('id', 'contract-uuid')
            ->andReturnSelf();
        $builder->shouldReceive('value')
            ->once()
            ->with('cpf_cnpj')
            ->andReturn(null);

        $clientFactory = Mockery::mock(ClientFactory::class);
        $clientFactory->shouldNotReceive('create');

        $service = new ContractCompanyCountSyncService();
        $this->injectProperty($service, 'idMappingService', $idMappingService);
        $this->injectProperty($service, 'guzzleClientFactory', $clientFactory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Contract 'contract-uuid' was not found in conciliador_web.");

        $service->sync('cont_acessus');
    }

    /**
     * @return array{0: ContractCompanyCountSyncService, 1: Client}
     */
    private function createServiceWithReadableContract(): array
    {
        $idMappingService = Mockery::mock(IdMappingService::class);
        $idMappingService->shouldReceive('resolve')
            ->once()
            ->with('contracts', 'cont_acessus', 'cont_acessus')
            ->andReturn('contract-uuid');

        $db = Mockery::mock('alias:Hyperf\DbConnection\Db');
        $connection = Mockery::mock();
        $builder = Mockery::mock();

        $db->shouldReceive('connection')
            ->once()
            ->with('conciliador_web')
            ->andReturn($connection);
        $connection->shouldReceive('table')
            ->once()
            ->with('contracts')
            ->andReturn($builder);
        $builder->shouldReceive('where')
            ->once()
            ->with('id', 'contract-uuid')
            ->andReturnSelf();
        $builder->shouldReceive('value')
            ->once()
            ->with('cpf_cnpj')
            ->andReturn('12345678000190');

        $client = Mockery::mock(Client::class);
        $clientFactory = Mockery::mock(ClientFactory::class);
        $clientFactory->shouldReceive('create')
            ->once()
            ->with(['timeout' => 5.0])
            ->andReturn($client);

        $service = new ContractCompanyCountSyncService();
        $this->injectProperty($service, 'idMappingService', $idMappingService);
        $this->injectProperty($service, 'guzzleClientFactory', $clientFactory);

        return [$service, $client];
    }
}
