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

use Hyperf\Coroutine\Coroutine;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Guzzle\ClientFactory;
use ReflectionClass;
use Throwable;

use function array_slice;
use function count;
use function Hyperf\Support\env;
use function in_array;

class DiscordNotificationService
{
    #[Inject]
    protected ClientFactory $guzzleClientFactory;

    public function notifyMigration(
        string $entity,
        string $contractId,
        int $httpStatus,
        array $result,
        array $batchSample = []
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $embed = $this->buildMigrationEmbed($entity, $contractId, $httpStatus, $result, $batchSample);
        Coroutine::create(fn () => $this->send($embed));
    }

    public function notifyException(
        string $entity,
        string $contractId,
        int $httpStatus,
        Throwable $e,
        array $batchSample = []
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $embed = $this->buildExceptionEmbed($entity, $contractId, $httpStatus, $e, $batchSample);
        Coroutine::create(fn () => $this->send($embed));
    }

    private function isEnabled(): bool
    {
        return filter_var(env('DISCORD_NOTIFICATIONS_ENABLED', true), FILTER_VALIDATE_BOOLEAN)
            && ! empty(env('DISCORD_WEBHOOK_URL', ''));
    }

    private function buildMigrationEmbed(
        string $entity,
        string $contractId,
        int $httpStatus,
        array $result,
        array $batchSample
    ): array {
        $inserted = (int) ($result['inserted'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $processingTimeMs = (int) ($result['processing_time_ms'] ?? 0);
        $errors = $result['errors'] ?? [];

        [$emoji, $color, $title] = $this->resolveMigrationAppearance($httpStatus, $entity, $inserted, $failed, $skipped);

        $fields = [
            ['name' => 'Contrato', 'value' => $contractId ?: 'N/A', 'inline' => true],
            ['name' => 'Módulo', 'value' => $entity, 'inline' => true],
            ['name' => 'Tempo', 'value' => $processingTimeMs > 0 ? "{$processingTimeMs}ms" : 'N/A', 'inline' => true],
            ['name' => 'Inseridos', 'value' => (string) $inserted, 'inline' => true],
            ['name' => 'Ignorados', 'value' => (string) $skipped, 'inline' => true],
            ['name' => 'Falhas', 'value' => (string) $failed, 'inline' => true],
        ];

        if (! empty($errors)) {
            $fields[] = [
                'name' => 'Erros',
                'value' => $this->truncate($this->formatErrors($errors), 1024),
                'inline' => false,
            ];
        }

        if (in_array($httpStatus, [207, 422, 413], true) && ! empty($batchSample)) {
            $fields[] = [
                'name' => 'Payload (primeiros registros)',
                'value' => $this->formatPayload($batchSample),
                'inline' => false,
            ];
        }

        return [
            'title' => "{$emoji} {$title}",
            'color' => $color,
            'fields' => $fields,
            'timestamp' => date('c'),
        ];
    }

    private function buildExceptionEmbed(
        string $entity,
        string $contractId,
        int $httpStatus,
        Throwable $e,
        array $batchSample
    ): array {
        [$emoji, $color, $title] = $this->resolveExceptionAppearance($httpStatus, $entity);

        $fields = [
            ['name' => 'Contrato', 'value' => $contractId ?: 'N/A', 'inline' => true],
            ['name' => 'Módulo', 'value' => $entity, 'inline' => true],
        ];

        if ($httpStatus === 500) {
            $fields[] = ['name' => 'Exceção', 'value' => (new ReflectionClass($e))->getShortName(), 'inline' => true];
            $fields[] = ['name' => 'Arquivo', 'value' => basename($e->getFile()) . ':' . $e->getLine(), 'inline' => true];
            $fields[] = [
                'name' => 'Mensagem',
                'value' => $this->truncate($e->getMessage(), 1024),
                'inline' => false,
            ];

            if (! empty($batchSample)) {
                $fields[] = [
                    'name' => 'Payload (primeiros registros)',
                    'value' => $this->formatPayload($batchSample),
                    'inline' => false,
                ];
            }
        } else {
            $fields[] = [
                'name' => 'Detalhe',
                'value' => $this->truncate($e->getMessage(), 1024),
                'inline' => false,
            ];
        }

        return [
            'title' => "{$emoji} {$title}",
            'color' => $color,
            'fields' => $fields,
            'timestamp' => date('c'),
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function resolveMigrationAppearance(
        int $httpStatus,
        string $entity,
        int $inserted,
        int $failed,
        int $skipped
    ): array {
        // 202 Accepted (async): derive appearance from result counts, same logic as sync
        $effectiveStatus = $httpStatus === 202
            ? $this->resolveStatusFromCounts($inserted, $failed)
            : $httpStatus;

        return match ($effectiveStatus) {
            201 => ['✅', 3066993, "{$entity} · {$inserted} inseridos"],
            207 => ['⚠️', 15105570, "{$entity} · {$inserted} inseridos, {$failed} falhas"],
            200 => ['ℹ️', 3447003, "{$entity} · {$skipped} registros já existiam (idempotente)"],
            422 => ['❌', 15158332, "{$entity} · falha total — {$failed} registros rejeitados"],
            413 => ['❌', 15158332, "{$entity} · payload muito grande"],
            default => ['📋', 9807270, "{$entity} · HTTP {$httpStatus}"],
        };
    }

    private function resolveStatusFromCounts(int $inserted, int $failed): int
    {
        if ($inserted > 0 && $failed === 0) {
            return 201;
        }
        if ($inserted > 0 && $failed > 0) {
            return 207;
        }
        if ($inserted === 0 && $failed > 0) {
            return 422;
        }
        return 200;
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function resolveExceptionAppearance(int $httpStatus, string $entity): array
    {
        return match ($httpStatus) {
            500 => ['🔥', 10038562, "{$entity} · Erro interno"],
            401 => ['🔒', 10181046, "{$entity} · API key inválida ou ausente"],
            403 => ['🚫', 10181046, "{$entity} · Contrato não autorizado"],
            429 => ['🚦', 15968788, "{$entity} · Rate limit excedido"],
            default => ['❌', 15158332, "{$entity} · HTTP {$httpStatus}"],
        };
    }

    private function formatErrors(array $errors): string
    {
        $lines = [];
        $limit = min(10, count($errors));

        for ($i = 0; $i < $limit; ++$i) {
            $error = $errors[$i];

            if (isset($error['legacy_id'], $error['validation_errors'])) {
                $legacyId = $error['legacy_id'];
                $messages = [];
                foreach ($error['validation_errors'] as $field => $fieldErrors) {
                    $messages[] = "{$field}: " . implode(', ', (array) $fieldErrors);
                }
                $lines[] = "• `{$legacyId}`: " . implode(' | ', $messages);
                continue;
            }

            $legacyId = $error['legacy_id'] ?? null;
            $message = $error['message'] ?? $error['detail'] ?? json_encode($error, JSON_UNESCAPED_UNICODE);

            $lines[] = $legacyId ? "• `{$legacyId}`: {$message}" : "• {$message}";
        }

        $remaining = count($errors) - $limit;
        if ($remaining > 0) {
            $lines[] = "... e mais {$remaining} erros";
        }

        return implode("\n", $lines);
    }

    private function formatPayload(array $payload): string
    {
        $json = json_encode(
            array_slice($payload, 0, 5),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        $formatted = "```json\n{$json}\n```";

        return $this->truncate($formatted, 1024);
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - 3) . '...';
    }

    private function send(array $embed): void
    {
        try {
            $webhookUrl = (string) env('DISCORD_WEBHOOK_URL', '');

            if (empty($webhookUrl)) {
                return;
            }

            $client = $this->guzzleClientFactory->create(['timeout' => 5.0]);
            $client->post($webhookUrl, [
                'json' => ['embeds' => [$embed]],
            ]);
        } catch (Throwable) {
            // Discord failures must never affect the application
        }
    }
}
