<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GryphlineBundle\Message\Profile\ImportPullsBackupMessage;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Service\Pull\PullBackupImportService;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
class ImportPullsBackupHandler
{
    private const int MAX_LOCK_RETRIES = 20;
    private const int LOCK_RETRY_DELAY_MS = 3000;

    public function __construct(
        private readonly GryphlineGameProfileRepository $gameProfileRepository,
        private readonly PullBackupImportService $importService,
        private readonly PullOperationTracker $operationTracker,
        private readonly MessageBusInterface $bus,
        #[Target('gryphline')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportPullsBackupMessage $message): void
    {
        $operationId = $message->getOperationId();
        $filePath = $message->getFilePath();
        $requeued = false;

        try {
            $profile = $this->gameProfileRepository->find($message->getGameProfileId());
            if ($profile === null) {
                $this->operationTracker->failOperation($operationId, sprintf(
                    'Game profile #%d not found.',
                    $message->getGameProfileId()
                ));

                return;
            }

            if (!is_file($filePath)) {
                $this->operationTracker->failOperation($operationId, 'Uploaded backup file is no longer available on disk.');

                return;
            }

            $summary = $this->importService->import(
                profile: $profile,
                importerKey: $message->getImporterKey(),
                filePath: $filePath,
                onProgress: function (PullImportProgress $progress) use ($operationId): void {
                    $this->operationTracker->updateBackupImportProgress($operationId, $progress);
                },
                dryRun: false,
                blockingLock: false,
            );

            $this->operationTracker->completeBackupImport($operationId, $summary);

            $this->logger->info(sprintf(
                'Backup pull import [%s] completed for profile #%d (%s): total=%d, imported=%d, skipped=%d.',
                $message->getImporterKey(),
                $profile->getId(),
                $profile->getNickname(),
                $summary->total,
                $summary->imported,
                $summary->skipped
            ));
        } catch (PullProfileLockedException $e) {
            if ($message->getLockAttempt() < self::MAX_LOCK_RETRIES) {
                $requeued = true;
                $this->bus->dispatch(
                    new ImportPullsBackupMessage(
                        operationId: $operationId,
                        gameProfileId: $message->getGameProfileId(),
                        importerKey: $message->getImporterKey(),
                        filePath: $filePath,
                        deleteFileAfter: $message->shouldDeleteFileAfter(),
                        lockAttempt: $message->getLockAttempt() + 1,
                    ),
                    [new DelayStamp(self::LOCK_RETRY_DELAY_MS)]
                );

                return;
            }

            $this->operationTracker->failOperation($operationId, $e->getMessage());
            $this->logger->warning('Backup pull import timed out waiting for profile lock.', [
                'operation_id' => $operationId,
                'profile_id' => $message->getGameProfileId(),
                'importer' => $message->getImporterKey(),
            ]);
        } catch (\Throwable $e) {
            $this->operationTracker->failOperation($operationId, $e->getMessage());
            $this->logger->error('Backup pull import failed.', [
                'operation_id' => $operationId,
                'profile_id' => $message->getGameProfileId(),
                'importer' => $message->getImporterKey(),
                'exception' => $e,
            ]);
        } finally {
            if (!$requeued && $message->shouldDeleteFileAfter() && is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }
}
