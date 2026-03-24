<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Unit\Service;

use App\Service\TokenService;
use Firebase\JWT\SignatureInvalidException;
use PHPUnit\Framework\Attributes\CoversClass;
use HyperfTest\UnitTestCase;

#[CoversClass(TokenService::class)]
final class TokenServiceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvValue('JWT_SECRET', 'unit-test-secret-key-with-32-bytes');
        $this->setEnvValue('JWT_TTL', '3600');
    }

    public function testGenerateCreatesTokenWithConfiguredClaims(): void
    {
        $service = new TokenService();
        $startedAt = time();

        $token = $service->generate('user-123', 'contract-456');
        $decoded = $service->decode($token);
        $finishedAt = time();

        $this->assertSame('user-123', $decoded->sub);
        $this->assertSame('contract-456', $decoded->contract_id);
        $this->assertSame(['migration:write', 'migration:read'], $decoded->abilities);
        $this->assertGreaterThanOrEqual($startedAt, $decoded->iat);
        $this->assertLessThanOrEqual($finishedAt, $decoded->iat);
        $this->assertSame(3600, $decoded->exp - $decoded->iat);
    }

    public function testGenerateSupportsTokensWithoutContractId(): void
    {
        $service = new TokenService();

        $token = $service->generate('user-123');
        $decoded = $service->decode($token);

        $this->assertObjectHasProperty('contract_id', $decoded);
        $this->assertNull($decoded->contract_id);
    }

    public function testDecodeThrowsWhenSecretDoesNotMatch(): void
    {
        $service = new TokenService();
        $token = $service->generate('user-123', 'contract-456');

        $this->setEnvValue('JWT_SECRET', 'another-secret-key-with-32-bytes');
        $this->expectException(SignatureInvalidException::class);

        $service->decode($token);
    }
}
