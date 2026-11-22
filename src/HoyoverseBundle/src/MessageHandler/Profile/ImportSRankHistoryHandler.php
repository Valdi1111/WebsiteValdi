<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\HoyoverseBundle\Message\Profile\ImportSRankHistoryMessage;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\SRankHistoryImportService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Runs an official S-rank history import and reports it to the operation tracker. While another pull operation holds
 * the profile lock the message is queued again (nothing has been requested yet), like a backup import.
 */
#[AsMessageHandler]
class ImportSRankHistoryHandler
{
    private const int MAX_LOCK_ATTEMPTS = 20;
    private const int LOCK_RETRY_DELAY_MS = 3000;

    public function __construct(
        private readonly HoyoverseGameProfileRepository $profileRepository,
        private readonly SRankHistoryImportService $importService,
        private readonly PullOperationTracker $operationTracker,
        private readonly MessageBusInterface $bus,
        #[Target('hoyoverse')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportSRankHistoryMessage $message): void
    {
        $operationId = $message->getOperationId();

        try {
            $profile = $this->profileRepository->find($message->getGameProfileId());
            if ($profile === null) {
                $this->operationTracker->failOperation($operationId, \sprintf('Game profile #%d not found.', $message->getGameProfileId()));

                return;
            }

            $summary = $this->importService->import(
                profile: $profile,
                onProgress: function (PullImportProgress $progress) use ($operationId): void {
                    $this->operationTracker->updateBackupImportProgress($operationId, $progress);
                },
                dryRun: false,
                blockingLock: false,
            );

            $this->operationTracker->completeBackupImport($operationId, $summary);
        } catch (PullProfileLockedException) {
            if ($message->getLockAttempt() < self::MAX_LOCK_ATTEMPTS) {
                $this->logger->info(\sprintf(
                    'Profile #%d is currently locked; re-queueing S-rank history import %s (attempt %d/%d).',
                    $message->getGameProfileId(),
                    $operationId,
                    $message->getLockAttempt() + 1,
                    self::MAX_LOCK_ATTEMPTS,
                ));

                $this->bus->dispatch(
                    new ImportSRankHistoryMessage($operationId, $message->getGameProfileId(), $message->getLockAttempt() + 1),
                    [new DelayStamp(self::LOCK_RETRY_DELAY_MS)],
                );

                return;
            }

            $this->logger->error(\sprintf('S-rank history import %s timed out waiting for the profile lock.', $operationId));
            $this->operationTracker->failOperation($operationId, 'Profile remained busy for too long. Please try again shortly.');
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('S-rank history import %s failed: %s', $operationId, $e->getMessage()), [
                'profile_id' => $message->getGameProfileId(),
                'exception' => $e,
            ]);
            $this->operationTracker->failOperation($operationId, $e->getMessage());
        }
    }
}
