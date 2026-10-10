<?php

namespace App\AnimeBundle\MessageHandler;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Message\EpisodeDownloadMessage;
use App\AnimeBundle\Model\EpisodeDownloadState;
use App\AnimeBundle\Service\Downloader\EpisodeDownloaderEngineInterface;
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
        private EpisodeDownloaderEngineInterface $downloadEngine,
    ) {
    }

    public function __invoke(EpisodeDownloadMessage $message): void
    {
        $episode = $this->entityManager
            ->getRepository(EpisodeDownload::class)
            ->find($message->getId());

        if (!$episode) {
            $this->logger->info("No episode found in queue");
            return;
        }

        $this->logger->info("Found episode in queue", ['id' => $episode->getId()]);

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

        // Mark episode as downloading
        $episode->setState(EpisodeDownloadState::downloading)
            ->setStarted(new \DateTime());
        $this->entityManager->flush();

        try {
            // Delegate the actual file retrieval to the pluggable download engine
            $this->downloadEngine->download($episode, $progressCallback);

            $episode->setState(EpisodeDownloadState::completed)
                ->setCompleted(new \DateTime());
            $this->logger->info("Downloaded episode successfully", [
                'id' => $episode->getId(),
                'file' => $episode->getFile(),
            ]);
        } catch (\Throwable $e) {
            $episode->setState(EpisodeDownloadState::error_downloading);
            $this->logger->error("Error downloading video: {$e->getMessage()}", [
                'id' => $episode->getId(),
                'exception' => $e,
            ]);
        }

        $this->entityManager->flush();
    }
}
