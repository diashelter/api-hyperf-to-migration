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

namespace HyperfTest;

use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use ReflectionClass;
use RuntimeException;

abstract class UnitTestCase extends TestCase
{
    protected const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    protected const UUID_V7_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /** @var array<string, array{exists: bool, value: ?string}> */
    private array $environmentBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->environmentBackup as $name => $backup) {
            if (! $backup['exists']) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }

            $value = $backup['value'] ?? '';
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $this->environmentBackup = [];

        if (class_exists(Mockery::class)) {
            Mockery::close();
        }

        parent::tearDown();
    }

    protected function setEnvValue(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->environmentBackup)) {
            $current = getenv($name);

            $this->environmentBackup[$name] = [
                'exists' => $current !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER),
                'value' => $current !== false ? $current : ($_ENV[$name] ?? $_SERVER[$name] ?? null),
            ];
        }

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            return;
        }

        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * Creates a Hyperf ResponseInterface mock that captures the JSON payload and HTTP status.
     *
     * Usage:
     *   $responseMock = $this->createResponseMock($capturedPayload, $capturedStatus);
     *   $this->injectProperty($controller, 'response', $responseMock);
     *   $controller->migrate();
     *   $this->assertSame(1, $capturedPayload['inserted']);
     *   $this->assertSame(201, $capturedStatus);
     */
    protected function createResponseMock(mixed &$capturedPayload = null, mixed &$capturedStatus = null): HyperfResponseInterface
    {
        $psrResponse = $this->createMock(PsrResponseInterface::class);
        $psrResponse->method('withStatus')
            ->willReturnCallback(static function (int $status) use (&$capturedStatus, $psrResponse) {
                $capturedStatus = $status;
                return $psrResponse;
            });
        $psrResponse->method('withHeader')->willReturnSelf();

        $hyperfResponse = $this->createMock(HyperfResponseInterface::class);
        $hyperfResponse->method('json')
            ->willReturnCallback(static function (array $payload) use (&$capturedPayload, $psrResponse) {
                $capturedPayload = $payload;
                return $psrResponse;
            });

        return $hyperfResponse;
    }

    protected function injectProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);

        while (! $reflection->hasProperty($property)) {
            $reflection = $reflection->getParentClass();

            if ($reflection === false) {
                throw new RuntimeException(sprintf('Property "%s" was not found in "%s".', $property, $object::class));
            }
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setValue($object, $value);
    }
}
