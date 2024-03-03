<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeDownloadState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YoutubeDl\Options;
use YoutubeDl\YoutubeDl;

#[AsCommand(name: 'anime:start-downloader', description: 'Start anime downloader')]
class AnimeStartDownloaderCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $animeEntityManager, private readonly ParameterBagInterface $params, string $name = null)
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $episode = $this->animeEntityManager->getRepository(EpisodeDownload::class)->findOneBy(['state' => EpisodeDownloadState::created], ['created' => 'ASC']);
        if (!$episode) {
            return Command::SUCCESS;
        }
        $yt = new YoutubeDl();
        $binPath = $this->params->get('anime.youtube_dl.path');
        if ($binPath) {
            $yt->setBinPath($binPath);
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
        $this->animeEntityManager->flush();
        $collection = $yt->download(
            Options::create()
                ->output('%(title)s.%(ext)s')
                ->noCheckCertificate(true)
                ->downloadPath($this->params->get('anime.base_folder') . $episode->getFolder())
                ->url($episode->getDownloadUrl())
        );
        foreach ($collection->getVideos() as $video) {
            if ($video->getError() !== null) {
                $episode->setState(EpisodeDownloadState::error_downloading);
            } else {
                $episode->setState(EpisodeDownloadState::completed)->setCompleted(new \DateTime());
            }
            $this->animeEntityManager->flush();
        }
        return Command::SUCCESS;
    }

}