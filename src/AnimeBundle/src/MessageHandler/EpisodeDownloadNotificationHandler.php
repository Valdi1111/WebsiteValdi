<?php

namespace App\AnimeBundle\MessageHandler;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeDownloadState;
use App\AnimeBundle\Message\EpisodeDownloadNotification;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use YoutubeDl\Options;
use YoutubeDl\YoutubeDl;

#[AsMessageHandler]
readonly class EpisodeDownloadNotificationHandler
{

    public function __construct(
        private LoggerInterface                               $animeEpisodeDownloaderLogger,
        private EntityManagerInterface                        $entityManager,
        #[Autowire('%anime.youtube_dl.bin_path%')] private string $youtubeDlBinPath,
        #[Autowire('%anime.base_folder%')] private string     $baseFolder)
    {
    }

    public function __invoke(EpisodeDownloadNotification $message): void
    {
        $episode = $this->entityManager->getRepository(EpisodeDownload::class)->findOneBy(['id' => $message->getId()]);
        if (!$episode) {
            $this->animeEpisodeDownloaderLogger->info("No episode found in queue");
            return;
        }
        $this->animeEpisodeDownloaderLogger->info("Found episode in queue", ['id' => $episode->getId()]);
        $yt = new YoutubeDl();
        if ($this->youtubeDlBinPath) {
            $yt->setBinPath($this->youtubeDlBinPath);
        }
        /*
        $yt->onProgress(static function (?string $progressTarget, string $percentage, string $size, string $speed, string $eta, ?string $totalTime) use ($output): void {
            echo "Download file: $progressTarget; Percentage: $percentage; Size: $size";
            if ($speed) {
                $output->writeln("; Speed: $speed");
            }
            if ($eta) {
                $output->writeln("; ETA: $eta");
            }
            if ($totalTime !== null) {
                $output->writeln("; Downloaded in: $totalTime");
            }
        });
        */
        $episode->setState(EpisodeDownloadState::downloading)->setStarted(new \DateTime());
        $this->entityManager->flush();
        $collection = $yt->download(
            Options::create()
                ->output('%(title)s.%(ext)s')
                ->noCheckCertificate(true)
                ->downloadPath($this->baseFolder . $episode->getFolder())
                ->url($episode->getDownloadUrl())
        );
        foreach ($collection->getVideos() as $video) {
            if ($video->getError() !== null) {
                $episode->setState(EpisodeDownloadState::error_downloading);
            } else {
                $episode->setState(EpisodeDownloadState::completed)->setCompleted(new \DateTime());
            }
            $this->entityManager->flush();
        }
    }
}