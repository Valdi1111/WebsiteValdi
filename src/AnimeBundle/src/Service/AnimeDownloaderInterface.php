<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeDownloadRequest;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use Exception;

interface AnimeDownloaderInterface
{

    /**
     * @param EpisodeDownloadRequest $downloadReq download request data
     * @return EpisodeDownload[]
     * @throws CacheAnimeNotFoundException
     * @throws Exception
     */
    public function createEpisodeDownloads(EpisodeDownloadRequest $downloadReq): array;

    /**
     * Website base url
     * @return string
     */
    public function getWebsiteUrl(): string;

    /**
     * Service name
     * @return string
     */
    public static function getServiceName(): string;

}