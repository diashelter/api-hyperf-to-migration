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

    #[Inject]
    protected ExportLayoutSyncService $exportLayoutSyncService;

    #[Inject]
    protected ContractCompanyCountSyncService $contractCompanyCountSyncService;

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
     * Falha numa entidade é capturada e marcada como `failed` em `entity_progress`.
     * A entidade `contracts` é a raiz do tenant; sem ela, todas as FKs por contrato
     * ficam inválidas, então o job é abortado cedo para evitar falhas em cascata.
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

        // Desabilitado migração dos layouts de exportação por enquanto, deve ser validados manualmente antes da migração
        // try {
        //     $this->exportLayoutSyncService->sync($legacyConnection, $contractId);
        // } catch (Throwable $e) {
        //     $this->logger->warning("[job {$jobId}] export layout sync failed (non-fatal): " . $e->getMessage());
        // }

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

                $resultStatus = (string) ($result['status'] ?? 'unknown');
                $failed = (int) ($result['failed'] ?? 0);

                if ($resultStatus === 'failed' || $resultStatus === 'completed_with_errors' || $failed > 0) {
                    $errorSummary[$entity] = $result['error_message']
                        ?? sprintf('Entity finished with status=%s failed=%d', $resultStatus, $failed);
                }

                $this->logger->info(sprintf(
                    '[job %s] entity %s done: status=%s inserted=%d failed=%d skipped=%d',
                    $jobId,
                    $entity,
                    $resultStatus,
                    (int) ($result['inserted'] ?? 0),
                    $failed,
                    (int) ($result['skipped'] ?? 0)
                ));

                if ($this->shouldAbortAfterEntity($entity, $resultStatus, $failed)) {
                    $message = $this->criticalEntityFailureMessage($entity, $errorSummary[$entity] ?? null);
                    $this->jobService->markFailed($jobId, $message);
                    $this->logger->error("[job {$jobId}] {$message}");
                    return;
                }
            } catch (Throwable $e) {
                $errorSummary[$entity] = $e->getMessage();
                $this->jobService->updateEntityProgress($jobId, $entity, [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                $this->logger->error("[job {$jobId}] entity {$entity} crashed: " . $e->getMessage());

                if ($this->shouldAbortAfterEntity($entity, 'failed', 0)) {
                    $message = $this->criticalEntityFailureMessage($entity, $e->getMessage());
                    $this->jobService->markFailed($jobId, $message);
                    $this->logger->error("[job {$jobId}] {$message}");
                    return;
                }
            }
        }

        if (empty($errorSummary)) {
            try {
                $this->contractCompanyCountSyncService->sync($contractId);
            } catch (Throwable $e) {
                $errorSummary['contract_company_count'] = $e->getMessage();
                $this->logger->error("[job {$jobId}] contract company_count sync failed: " . $e->getMessage());
            }
        }

        $this->jobService->markCompleted($jobId, $errorSummary ?: null);
    }

    private function shouldAbortAfterEntity(string $entity, string $status, int $failed): bool
    {
        if ($entity !== 'contracts') {
            return false;
        }

        return $status !== 'completed' || $failed > 0;
    }

    private function criticalEntityFailureMessage(string $entity, ?string $cause): string
    {
        $message = sprintf(
            "Critical entity '%s' failed; aborting migration before dependent entities.",
            $entity
        );

        return $cause !== null && $cause !== ''
            ? "{$message} Cause: {$cause}"
            : $message;
    }
}
