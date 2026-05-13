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

use App\Exception\ValidationFailedException;
use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Throwable;

class LegacyConnectionFactory
{
    private const BASE_CONNECTION = 'legacy_database';

    #[Inject]
    protected ConfigInterface $config;

    /**
     * Registra uma nova conexão de banco de dados para o banco legado, usando as configurações definidas em `databases.legacy_database` como base.
     * O nome da conexão será o nome lógico do banco legado fornecido.
     * `databases.<connection_name>` (se ainda não registrada), roda smoke test
     * e devolve o nome da connection pronto para uso em `Db::connection()`.
     *
     * @throws ValidationFailedException quando o nome não for fornecido ou a conexão falhar no smoke test
     */
    public function connect(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new ValidationFailedException('Legacy database name is required.');
        }

        $connectionName = $this->connectionName($name);
        $config = $this->config->get('databases.' . self::BASE_CONNECTION);
        $config['database'] = $name;

        $this->registerConnection($connectionName, $config);
        $this->smokeTest($connectionName, $name);

        return $connectionName;
    }

    private function connectionName(string $database): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_]/', '_', $database) ?: 'database';
        $slug = trim($slug, '_') ?: 'database';

        return sprintf(
            '%s_%s_%s',
            self::BASE_CONNECTION,
            strtolower($slug),
            substr(sha1($database), 0, 12)
        );
    }

    private function registerConnection(string $connectionName, array $config): void
    {
        $this->config->set("databases.{$connectionName}", $config);
    }

    private function smokeTest(string $connectionName, string $expectedDatabase): void
    {
        try {
            $result = Db::connection($connectionName)->selectOne('SELECT current_database() AS db');
            $actualDatabase = is_array($result)
                ? (string) ($result['db'] ?? '')
                : (string) ($result->db ?? '');

            if ($actualDatabase !== $expectedDatabase) {
                throw new ValidationFailedException(sprintf(
                    "Connected legacy database mismatch: expected '%s', got '%s' on connection '%s'.",
                    $expectedDatabase,
                    $actualDatabase,
                    $connectionName
                ));
            }
        } catch (ValidationFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ValidationFailedException(
                "Failed to connect to legacy database '{$expectedDatabase}' using connection '{$connectionName}': " . $e->getMessage()
            );
        }
    }
}
