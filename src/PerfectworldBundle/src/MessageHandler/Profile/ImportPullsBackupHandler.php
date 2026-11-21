<?php

namespace App\PerfectworldBundle\MessageHandler\Profile;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\PerfectworldBundle\Message\Profile\ImportPullsBackupMessage;
use App\PerfectworldBundle\Repository\PerfectworldGameProfileRepository;
use App\PerfectworldBundle\Service\Pull\PullBackupImportService;
use App\PerfectworldBundle\Service\Pull\PullOperationTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ImportPullsBackupHandler
{
    public function __construct(
        private readonly PerfectworldGameProfileRepository $gameProfileRepository,
        private readonly PullBackupImportService $importService,
        private readonly PullOperationTracker $operationTracker,
        #[Target('perfectworld')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportPullsBackupMessage $message): void
    {
        $operationId = $message->getOperationId();

        try {
            $profile = $this->gameProfileRepository->find($message->getGameProfileId());
            if ($profile === null) {
                $this->operationTracker->failOperation($operationId, sprintf('Game profile #%d not found.', $message->getGameProfileId()));

                return;
            }

            foreach ($message->getFilePaths() as $filePath) {
                if (!is_file($filePath)) {
                    $this->operationTracker->failOperation($operationId, 'Uploaded files are no longer available on disk.');

                    return;
                }
            }

            $summary = $this->importService->import(
                profile: $profile,
                importerKey: $message->getImporterKey(),
                filePaths: $message->getFilePaths(),
                onProgress: function (PullImportProgress $progress) use ($operationId): void {
                    $this->operationTracker->updateBackupImportProgress($operationId, $progress);
                },
            );

            $this->operationTracker->completeBackupImport($operationId, $summary);

            $this->logger->info(sprintf(
                'Pull import [%s] completed for profile #%d: total=%d, imported=%d, updated=%d, skipped=%d.',
                $message->getImporterKey(),
                $message->getGameProfileId(),
                $summary->total,
                $summary->imported,
                $summary->updated,
                $summary->skipped,
            ));
        } catch (PullProfileLockedException) {
            // No automatic sync competes for the lock: another import is already running
            $this->operationTracker->failOperation($operationId, 'Another import is already running for this profile.');
        } catch (\Throwable $e) {
            $this->operationTracker->failOperation($operationId, $e->getMessage());
            $this->logger->error('Pull import failed.', [
                'operation_id' => $operationId,
                'profile_id' => $message->getGameProfileId(),
                'importer' => $message->getImporterKey(),
                'exception' => $e,
            ]);
        } finally {
            foreach ($message->getFilePaths() as $filePath) {
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }
        }
    }
}
