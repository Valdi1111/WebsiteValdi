<?php

namespace App\AnimeBundle\Service\Tracker;

use App\AnimeBundle\Model\TrackerMediaTitle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract readonly class AbstractAnimeTracker implements AnimeTrackerInterface
{

    public function __construct(
        protected HttpClientInterface $httpClient,
    )
    {
    }

    public function fetchAnimeTitle(int $id): TrackerMediaTitle
    {
        $url = $this->getAnimeUrl($id);

        return new TrackerMediaTitle(
            id: $id,
            url: $url,
            title: $this->scrapeOgTitle($url)
        );
    }

    public function fetchMangaTitle(int $id): TrackerMediaTitle
    {
        $url = $this->getMangaUrl($id);

        return new TrackerMediaTitle(
            id: $id,
            url: $url,
            title: $this->scrapeOgTitle($url)
        );
    }

    protected function scrapeOgTitle(string $url): ?string
    {
        try {
            $response = $this->httpClient->request(Request::METHOD_GET, $url);
            $crawler = new Crawler($response->getContent());

            return $crawler
                ->filter('meta[property="og:title"]')
                ->attr('content');
        } catch (\Throwable $e) {
            return null;
        }
    }

}
