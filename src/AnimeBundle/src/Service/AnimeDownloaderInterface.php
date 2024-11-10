<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use Exception;

interface AnimeDownloaderInterface
{

    /**
     * @param string $urlPath anime url (without hostname)
     * @param bool $allEpisodes query all episodes
     * @param bool $filter filter if not present in MyAnimeList cache
     * @param bool $save save to database
     * @return EpisodeDownload[]
     * @throws CacheAnimeNotFoundException
     * @throws Exception
     */
    public function createEpisodeDownloads(string $urlPath, bool $allEpisodes = false, bool $filter = true, bool $save = true): array;

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