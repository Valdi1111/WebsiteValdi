<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Exception\ProviderFetchException;
use App\AnimeBundle\Exception\ScrapeParsingException;
use App\AnimeBundle\Exception\SiteUnavailableException;
use App\AnimeBundle\Model\EpisodeDownloadRequest;
use App\AnimeBundle\Model\ScrapedEpisode;

interface AnimeDownloaderInterface
{
    /**
     * Determine if the provider can handle the given URL
     *
     * @param string $url
     * @return bool
     */
    public function supports(string $url): bool;

    /**
     * Fetch newly released episode relative/absolute URLs
     *
     * @return string[]
     * @throws SiteUnavailableException
     * @throws ProviderFetchException
     */
    public function fetchLatestEpisodeUrls(): array;

    /**
     * Scrape episode metadata and download references
     *
     * @param EpisodeDownloadRequest $downloadReq download request data
     * @return ScrapedEpisode[]
     * @throws SiteUnavailableException
     * @throws ProviderFetchException
     * @throws ScrapeParsingException
     */
    public function scrapeEpisodes(EpisodeDownloadRequest $downloadReq): array;

    /**
     * Extract or refresh direct download URL for an episode
     *
     * @param string $episodeUrl
     * @return string
     * @throws SiteUnavailableException
     * @throws ProviderFetchException
     * @throws ScrapeParsingException
     */
    public function extractDownloadUrl(string $episodeUrl): string;

    /**
     * Website base url
     *
     * @return string
     */
    public function getWebsiteUrl(): string;

    /**
     * Website regex url
     *
     * @return string
     */
    public function getWebsiteUrlRegex(): string;

    /**
     * Service name
     *
     * @return string
     */
    public static function getServiceName(): string;
}
