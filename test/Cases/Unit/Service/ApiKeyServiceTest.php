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

use App\Service\ApiKeyService;
use HyperfTest\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ApiKeyService::class)]
final class ApiKeyServiceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvValue('MIGRATION_API_KEY', 'primary-api-key');
        $this->setEnvValue('MIGRATION_API_KEYS', 'secondary-api-key');
        $this->setEnvValue('MIGRATION_API_KEY_ENCRYPTION_KEY', 'unit-encryption-key-with-32-bytes');
    }

    public function testEncryptAndDecryptJsonPayload(): void
    {
        $service = new ApiKeyService();

        $encrypted = $service->encryptPayload([
            'api_key' => 'primary-api-key',
            'contract_id' => 'contract-1',
            'user_id' => 'user-1',
            'exp' => 1893456000,
        ]);

        $payload = $service->decryptPayload($encrypted);

        $this->assertSame('primary-api-key', $payload['api_key']);
        $this->assertSame('contract-1', $payload['contract_id']);
        $this->assertSame('user-1', $payload['user_id']);
        $this->assertSame(1893456000, $payload['exp']);
        $this->assertTrue($service->matchesConfiguredApiKey($payload['api_key']));
    }

    public function testEncryptAndDecryptPlainApiKey(): void
    {
        $service = new ApiKeyService();

        $encrypted = $service->encryptPayload('secondary-api-key');
        $payload = $service->decryptPayload($encrypted);

        $this->assertSame('secondary-api-key', $payload['api_key']);
        $this->assertNull($payload['contract_id']);
        $this->assertNull($payload['user_id']);
        $this->assertNull($payload['exp']);
        $this->assertTrue($service->matchesConfiguredApiKey($payload['api_key']));
    }

    public function testDecryptRejectsInvalidPayload(): void
    {
        $service = new ApiKeyService();

        $this->expectException(InvalidArgumentException::class);

        $service->decryptPayload('not-encrypted');
    }
}
