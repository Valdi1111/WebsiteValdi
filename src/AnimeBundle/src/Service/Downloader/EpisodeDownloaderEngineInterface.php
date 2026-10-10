<?php

namespace App\AnimeBundle\Service\Downloader;

use App\AnimeBundle\Entity\EpisodeDownload;

interface EpisodeDownloaderEngineInterface
{
    /**
     * Download the episode file to destination folder
     *
     * @param callable(?string $target, string $percentage, string $size, ?string $speed, ?string $eta, ?string $totalTime): void|null $onProgress
     */
    public function download(EpisodeDownload $episode, ?callable $onProgress = null): void;

    public function supports(EpisodeDownload $episode): bool;
}
