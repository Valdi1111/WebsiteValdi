<?php

namespace App\AnimeBundle\MessageHandler;

use App\AnimeBundle\Message\EpisodeDownloadMessage;
use App\AnimeBundle\Model\EpisodeDownloadState;
use App\AnimeBundle\Repository\EpisodeDownloadAttemptRepository;
use App\AnimeBundle\Service\Downloader\EpisodeDownloaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class EpisodeDownloadMessageHandler
{
    public function __construct(
        #[Target('anime.episode_downloader')]
        private LoggerInterface                  $logger,
        private EntityManagerInterface           $entityManager,
        private EpisodeDownloadAttemptRepository $attemptRepository,
        private EpisodeDownloaderInterface       $downloader,
    )
    {
    }

    public function __invoke(EpisodeDownloadMessage $message): void
    {
        $attempt = $this->attemptRepository->find($message->getAttemptId());
        if (!$attempt) {
            $this->logger->info("No episode download attempt #{$message->getAttemptId()} found in queue");
            return;
        }

        $download = $attempt->getEpisodeDownload();
        if (!$download) {
            $this->logger->error("Attempt #{$attempt->getId()} has no associated EpisodeDownload entity");
            return;
        }

        // Progress callback to keep the logger updated during download execution
        $progressCallback = function (
            ?string $progressTarget,
            string  $percentage,
            string  $size,
            ?string $speed,
            ?string $eta,
            ?string $totalTime
        ): void {
            $context = [
                "Percentage" => $percentage,
                "Size" => $size,
            ];
            if ($speed) {
                $context["Speed"] = $speed;
            }
            if ($eta) {
                $context["ETA"] = $eta;
            }
            if ($totalTime !== null) {
                $context["Downloaded in"] = $totalTime;
            }
            $this->logger->info("Downloading $progressTarget", $context);
        };

        $now = new \DateTime();

        // Mark both parent download and active attempt as downloading
        $download->setState(EpisodeDownloadState::downloading);
        $download->setStarted($now);

        $attempt->setState(EpisodeDownloadState::downloading);
        $attempt->setStarted($now);

        $this->entityManager->flush();

        try {
            // Delegate the actual file retrieval to the pluggable download engine
            $this->downloader->download($download, $progressCallback);

            $completedAt = new \DateTime();

            // Mark download and attempt as completed
            $download->setState(EpisodeDownloadState::completed);
            $download->setCompleted($completedAt);

            $attempt->setState(EpisodeDownloadState::completed);
            $attempt->setCompleted($completedAt);
            $attempt->setErrorMessage(null);
            $attempt->setErrorTrace(null);

            $this->logger->info("Downloaded episode successfully", [
                'id' => $download->getId(),
                'attempt_id' => $attempt->getId(),
                'file' => $download->getFile(),
            ]);
        } catch (\Throwable $e) {
            $failedAt = new \DateTime();

            // Mark download as failed
            $download->setState(EpisodeDownloadState::error_downloading);

            // Record failure details and trace on current attempt
            $attempt->setState(EpisodeDownloadState::error_downloading);
            $attempt->setErrorMessage($e->getMessage());
            $attempt->setErrorTrace($e->getTraceAsString());
            $attempt->setCompleted($failedAt);

            $this->logger->error("Failed downloading episode #{$download->getId()} (Attempt #{$attempt->getId()}): " . $e->getMessage(), [
                'exception' => $e,
            ]);
        } finally {
            $this->entityManager->flush();
        }
    }
}
