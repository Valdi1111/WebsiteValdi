<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\HoyoverseBundle\Message\Profile\ImportPullsBackupMessage;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\Pull\PullBackupImportService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
class ImportPullsBackupHandler
{
    private const int MAX_LOCK_ATTEMPTS = 20;
    private const int LOCK_RETRY_DELAY_MS = 3000;

    public function __construct(
        private readonly HoyoverseGameProfileRepository $profileRepository,
        private readonly PullBackupImportService $importService,
        private readonly PullOperationTracker $operationTracker,
        private readonly MessageBusInterface $bus,
        #[Target('hoyoverse')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportPullsBackupMessage $message): void
    {
        $operationId = $message->getOperationId();
        $filePath = $message->getFilePath();
        $shouldCleanup = $message->shouldDeleteFileAfter();
        $requeued = false;

        try {
            $profile = $this->profileRepository->find($message->getGameProfileId());
            if ($profile === null) {
                $this->operationTracker->failOperation($operationId, sprintf('Game profile #%d not found.', $message->getGameProfileId()));

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
        } catch (PullProfileLockedException $e) {
            if ($message->getLockAttempt() < self::MAX_LOCK_ATTEMPTS) {
                $requeued = true;
                $this->logger->info(sprintf(
                    'Profile #%d is currently locked; re-queueing backup import %s (attempt %d/%d).',
                    $message->getGameProfileId(),
                    $operationId,
                    $message->getLockAttempt() + 1,
                    self::MAX_LOCK_ATTEMPTS
                ));

                $this->bus->dispatch(
                    new ImportPullsBackupMessage(
                        operationId: $operationId,
                        gameProfileId: $message->getGameProfileId(),
                        importerKey: $message->getImporterKey(),
                        filePath: $filePath,
                        deleteFileAfter: $shouldCleanup,
                        lockAttempt: $message->getLockAttempt() + 1,
                    ),
                    [new DelayStamp(self::LOCK_RETRY_DELAY_MS)]
                );

                return;
            }

            $this->logger->error(sprintf('Backup import %s timed out waiting for profile lock: %s', $operationId, $e->getMessage()));
            $this->operationTracker->failOperation($operationId, 'Profile remained busy for too long. Please try again shortly.');
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Backup import %s failed: %s', $operationId, $e->getMessage()), [
                'profile_id' => $message->getGameProfileId(),
                'importer' => $message->getImporterKey(),
                'exception' => $e,
            ]);
            $this->operationTracker->failOperation($operationId, $e->getMessage());
        } finally {
            if ($shouldCleanup && !$requeued && $filePath !== '' && is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }
}
