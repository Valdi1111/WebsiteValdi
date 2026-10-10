<?php

namespace App\AnimeBundle\MessageHandler;

use App\AnimeBundle\Entity\EpisodeDownloadAttempt;
use App\AnimeBundle\Message\EpisodeDownloadMessage;
use App\AnimeBundle\Model\EpisodeDownloadState;
use App\AnimeBundle\Repository\EpisodeDownloadRepository;
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
        private LoggerInterface            $logger,
        private EntityManagerInterface     $entityManager,
        private EpisodeDownloadRepository  $downloadRepository,
        private EpisodeDownloaderInterface $downloader,
    ) {
    }

    public function __invoke(EpisodeDownloadMessage $message): void
    {
        $download = $this->downloadRepository->find($message->getId());
        if (!$download) {
            $this->logger->info("No episode #{$download->getId()} found in queue");
            return;
        }

        // Retrieve existing unstarted attempt or create a new one
        $attempt = $download->getLastAttempt();
        if ($attempt === null || $attempt->getState() !== EpisodeDownloadState::created) {
            $attempt = new EpisodeDownloadAttempt()
                ->setState(EpisodeDownloadState::created);
            $download->addEpisodeDownloadAttempt($attempt);
        }

        // Progress callback to keep the logger updated during download execution
        $progressCallback = function (
            ?string $progressTarget,
            string $percentage,
            string $size,
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

        // Mark both download and active attempt as downloading
        $download->setState(EpisodeDownloadState::downloading);
        $download->setStarted(new \DateTime());

        $attempt->setState(EpisodeDownloadState::downloading);
        $attempt->setStarted(new \DateTime());

        $this->entityManager->flush();

        try {
            // Delegate the actual file retrieval to the pluggable download engine
            $this->downloader->download($download, $progressCallback);

            // Mark download and attempt as completed
            $download->setState(EpisodeDownloadState::completed);
            $download->setCompleted(new \DateTime());

            $attempt->setState(EpisodeDownloadState::completed);
            $attempt->setCompleted(new \DateTime());
            $attempt->setErrorMessage(null);
            $attempt->setErrorTrace(null);

            $this->logger->info("Downloaded episode successfully", [
                'id' => $download->getId(),
                'file' => $download->getFile(),
            ]);
        } catch (\Throwable $e) {
            // Mark download as failed
            $download->setState(EpisodeDownloadState::error_downloading);

            // Record failure details and trace on current attempt
            $attempt->setState(EpisodeDownloadState::error_downloading);
            $attempt->setErrorMessage($e->getMessage());
            $attempt->setErrorTrace($e->getTraceAsString());
            $attempt->setCompleted(new \DateTime());

            $this->logger->error("Failed downloading episode #{$download->getId()}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        } finally {
            $this->entityManager->flush();
        }
    }
}
