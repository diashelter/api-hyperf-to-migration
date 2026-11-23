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

namespace App\Crontab;

use App\Listener\DbQueryExecutedListener;
use App\Service\LookupCacheService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * Atualiza o lookup_cache de layouts_admin a partir do conciliador_web (crontab a cada minuto).
 *
 * Loga info só quando o seed muda algo; falha vira log de erro e não é relançada —
 * o minuto seguinte faz o papel do retry. As queries do seed não vão para o log SQL
 * (DbQueryExecutedListener), para não gravar ~200 KB por minuto sem mudança.
 */
class SeedLayoutsAdminLookupTask
{
    public const ENTITY = 'layouts_admin';

    #[Inject]
    protected LookupCacheService $lookupCacheService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function execute(): void
    {
        $logger = $this->loggerFactory->get('lookup-cache');

        Context::set(DbQueryExecutedListener::SKIP_LOG_CONTEXT_KEY, true);

        try {
            $result = $this->lookupCacheService->seedWithChanges(self::ENTITY);
        } catch (Throwable $e) {
            $logger->error('Falha ao sincronizar lookup_cache layouts_admin', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        } finally {
            Context::destroy(DbQueryExecutedListener::SKIP_LOG_CONTEXT_KEY);
        }

        if (! $result->hasChanges()) {
            return;
        }

        $logger->info('lookup_cache layouts_admin sincronizado', [
            'total' => $result->total,
            'inserted' => $result->inserted,
            'updated' => $result->updated,
            'removed' => $result->removed,
        ]);
    }
}
