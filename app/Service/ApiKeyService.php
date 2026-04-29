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

use InvalidArgumentException;
use RuntimeException;

use function Hyperf\Support\env;

class ApiKeyService
{
    private const CIPHER = 'aes-256-gcm';

    private const VERSION = 'v1';

    /**
     * @param array<string, mixed>|string $payload
     */
    public function encryptPayload(array|string $payload): string
    {
        $plainText = is_array($payload)
            ? json_encode($payload, JSON_THROW_ON_ERROR)
            : $payload;

        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if (! is_string($cipherText)) {
            throw new RuntimeException('Failed to encrypt API key payload.');
        }

        return implode('.', [
            self::VERSION,
            $this->base64UrlEncode($iv),
            $this->base64UrlEncode($tag),
            $this->base64UrlEncode($cipherText),
        ]);
    }

    /**
     * @return array{api_key: string, contract_id: ?string, user_id: ?string, exp: ?int}
     */
    public function decryptPayload(string $encryptedApiKey): array
    {
        $plainText = $this->decrypt($encryptedApiKey);
        $decoded = json_decode($plainText, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $apiKey = $this->optionalString($decoded['api_key'] ?? $decoded['key'] ?? null);

            if ($apiKey === null) {
                throw new InvalidArgumentException('Encrypted API key payload is missing api_key.');
            }

            return [
                'api_key' => $apiKey,
                'contract_id' => $this->optionalString($decoded['contract_id'] ?? null),
                'user_id' => $this->optionalString($decoded['user_id'] ?? $decoded['sub'] ?? null),
                'exp' => $this->optionalInt($decoded['exp'] ?? null),
            ];
        }

        if ($plainText === '') {
            throw new InvalidArgumentException('Encrypted API key payload is empty.');
        }

        return [
            'api_key' => $plainText,
            'contract_id' => null,
            'user_id' => null,
            'exp' => null,
        ];
    }

    public function matchesConfiguredApiKey(string $apiKey): bool
    {
        $configuredApiKeys = $this->configuredApiKeys();

        if ($configuredApiKeys === []) {
            throw new RuntimeException('MIGRATION_API_KEY or MIGRATION_API_KEYS must be configured.');
        }

        foreach ($configuredApiKeys as $configuredApiKey) {
            if (hash_equals($configuredApiKey, $apiKey)) {
                return true;
            }
        }

        return false;
    }

    private function decrypt(string $encryptedApiKey): string
    {
        $parts = explode('.', trim($encryptedApiKey));

        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new InvalidArgumentException('Encrypted API key has an invalid format.');
        }

        [, $encodedIv, $encodedTag, $encodedCipherText] = $parts;

        $iv = $this->base64UrlDecode($encodedIv);
        $tag = $this->base64UrlDecode($encodedTag);
        $cipherText = $this->base64UrlDecode($encodedCipherText);

        if (strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw new InvalidArgumentException('Encrypted API key has invalid cryptographic parameters.');
        }

        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (! is_string($plainText)) {
            throw new InvalidArgumentException('Encrypted API key could not be decrypted.');
        }

        return $plainText;
    }

    /**
     * @return list<string>
     */
    private function configuredApiKeys(): array
    {
        $keys = [
            (string) env('MIGRATION_API_KEY', ''),
            ...explode(',', (string) env('MIGRATION_API_KEYS', '')),
        ];

        $keys = array_filter(array_map('trim', $keys), static fn (string $key): bool => $key !== '');

        return array_values(array_unique($keys));
    }

    private function encryptionKey(): string
    {
        $configuredKey = trim((string) env('MIGRATION_API_KEY_ENCRYPTION_KEY', ''));

        if ($configuredKey === '') {
            throw new RuntimeException('MIGRATION_API_KEY_ENCRYPTION_KEY must be configured.');
        }

        if (str_starts_with($configuredKey, 'base64:')) {
            $decodedKey = base64_decode(substr($configuredKey, 7), true);

            if (! is_string($decodedKey) || $decodedKey === '') {
                throw new RuntimeException('MIGRATION_API_KEY_ENCRYPTION_KEY is not valid base64.');
            }

            return strlen($decodedKey) === 32 ? $decodedKey : hash('sha256', $decodedKey, true);
        }

        return strlen($configuredKey) === 32 ? $configuredKey : hash('sha256', $configuredKey, true);
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException('API key payload contains a non-string value.');
        }

        return (string) $value;
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException('API key payload contains an invalid exp value.');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw new InvalidArgumentException('Encrypted API key contains invalid base64url data.');
        }

        return $decoded;
    }
}
