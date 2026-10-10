<?php

namespace App\AnimeBundle\Service\Tracker;

use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Exception\CacheRefreshException;
use App\AnimeBundle\Model\TrackerMediaTitle;

interface AnimeTrackerInterface
{
    /**
     * Unique identifier for the tracker (e.g. 'myanimelist', 'anilist')
     */
    public static function getTrackerName(): string;

    /**
     * Check if a series ID is tracked in this tracker's anime cache
     */
    public function existsInAnimeCache(int $id): bool;

    /**
     * Check if a series ID is tracked in this tracker's manga cache
     */
    public function existsInMangaCache(int $id): bool;

    /**
     * Refresh anime cache from the remote provider
     *
     * @return ListAnime[]
     * @throws CacheRefreshException
     */
    public function refreshAnimeCache(): array;

    /**
     * Refresh manga cache from the remote provider
     *
     * @return ListManga[]
     * @throws CacheRefreshException
     */
    public function refreshMangaCache(): array;

    public function getAnimeUrl(int $id): string;

    public function getMangaUrl(int $id): string;

    public function fetchAnimeTitle(int $id): TrackerMediaTitle;

    public function fetchMangaTitle(int $id): TrackerMediaTitle;
}
