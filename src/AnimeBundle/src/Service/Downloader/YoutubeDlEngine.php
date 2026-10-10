<?php

namespace App\AnimeBundle\Service\Downloader;

use App\AnimeBundle\Entity\EpisodeDownload;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use YoutubeDl\Options;
use YoutubeDl\YoutubeDl;

readonly class YoutubeDlEngine implements EpisodeDownloaderEngineInterface
{
    public function __construct(
        #[Autowire(param: 'anime.youtube_dl.bin_path')]
        private string $youtubeDlBinPath,
        #[Autowire(param: 'anime.base_folder')]
        private string $baseFolder,
    ) {
    }

    public function supports(EpisodeDownload $episode): bool
    {
        return true;
    }

    public function download(EpisodeDownload $episode, ?callable $onProgress = null): void
    {
        $yt = new YoutubeDl();
        if ($this->youtubeDlBinPath) {
            $yt->setBinPath($this->youtubeDlBinPath);
        }

        if ($onProgress !== null) {
            $yt->onProgress($onProgress);
        }

        $collection = $yt->download(
            Options::create()
                ->output($episode->getFile())
                ->noCheckCertificate(true)
                ->downloadPath($this->baseFolder . $episode->getFolder())
                ->url($episode->getDownloadUrl())
                ->fragmentRetries(999)
                ->skipUnavailableFragments(true)
                ->verbose(true)
        );

        foreach ($collection->getVideos() as $video) {
            if ($video->getError() !== null) {
                throw new Exception("YoutubeDl error: " . $video->getError());
            }
        }
    }
}
