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

        $connetionName = 'legacy_database';
        $config = $this->config->get("databases.{$connetionName}");
        $config['database'] = $name;

        $this->registerConnection($connetionName, $config);
        $this->smokeTest($connetionName);

        return $connetionName;
    }

    private function registerConnection(string $connectionName, array $config): void
    {
        $this->config->set("databases.{$connectionName}", $config);
    }

    private function smokeTest(string $connectionName): void
    {
        try {
            Db::connection($connectionName)->select('SELECT 1');
        } catch (Throwable $e) {
            throw new ValidationFailedException(
                "Failed to connect to legacy database '{$connectionName}': " . $e->getMessage()
            );
        }
    }
}
