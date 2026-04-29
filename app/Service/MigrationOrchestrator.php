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

use App\PullMode\Source\AbstractLegacySource;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class MigrationOrchestrator
{
    #[Inject]
    protected MigrationJobService $jobService;

    #[Inject]
    protected EntityMigrator $entityMigrator;

    #[Inject]
    protected LegacyConnectionFactory $legacyConnectionFactory;

    private LoggerInterface $logger;

    private ContainerInterface $container;

    public function __construct(LoggerFactory $loggerFactory, ContainerInterface $container)
    {
        $this->logger = $loggerFactory->get('migration-orchestrator');
        $this->container = $container;
    }

    /**
     * Itera as Sources em ORDEM DE FK (definida em EntityMetadataRegistry::sources()),
     * delega cada uma ao EntityMigrator, e mantém o status do job atualizado.
     *
     * Falha numa entidade é capturada e marcada como `failed` em `entity_progress`,
     * mas o job continua para as demais (decisão "continuar em falha" travada no PLAN).
     */
    public function run(string $jobId): void
    {
        $status = $this->jobService->getStatus($jobId);

        if ($status === null) {
            $this->logger->error("Migration job '{$jobId}' not found.");
            return;
        }

        $legacyDb = $status['legacy_db'];
        $contractId = $status['contract_id'];

        try {
            $legacyConnection = $this->legacyConnectionFactory->connect($legacyDb);
        } catch (Throwable $e) {
            $this->jobService->markFailed($jobId, "Cannot connect to legacy DB '{$legacyDb}': " . $e->getMessage());
            return;
        }

        $this->jobService->markProcessing($jobId);

        $errorSummary = [];

        foreach (EntityMetadataRegistry::sources() as $sourceClass) {
            /** @var AbstractLegacySource $source */
            $source = $this->container->get($sourceClass);
            $entity = $source->entity();
            $entityProgress = $this->jobService->getEntityProgress($jobId, $entity);

            if (($entityProgress['status'] ?? null) === 'completed') {
                continue;
            }

            $this->jobService->setCurrentEntity($jobId, $entity);
            $this->logger->info("[job {$jobId}] starting entity {$entity}");

            try {
                $result = $this->entityMigrator->migrate($jobId, $source, $legacyConnection, $contractId);

                if (($result['status'] ?? null) === 'failed') {
                    $errorSummary[$entity] = $result['error_message'] ?? 'unknown error';
                }

                $this->logger->info(sprintf(
                    '[job %s] entity %s done: status=%s inserted=%d failed=%d skipped=%d',
                    $jobId,
                    $entity,
                    $result['status'] ?? 'unknown',
                    (int) ($result['inserted'] ?? 0),
                    (int) ($result['failed'] ?? 0),
                    (int) ($result['skipped'] ?? 0)
                ));
            } catch (Throwable $e) {
                $errorSummary[$entity] = $e->getMessage();
                $this->jobService->updateEntityProgress($jobId, $entity, [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                $this->logger->error("[job {$jobId}] entity {$entity} crashed: " . $e->getMessage());
            }
        }

        $this->jobService->markCompleted($jobId, $errorSummary ?: null);
    }
}
