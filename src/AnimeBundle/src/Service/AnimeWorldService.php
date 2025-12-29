<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeDownloadRequest;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\SeasonFolder;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias('animeworld.anime.downloader')]
#[AsAlias('App\AnimeBundle\Service\AnimeDownloaderInterface $animeWorldDownloader')]
readonly class AnimeWorldService implements AnimeDownloaderInterface
{
    private HttpBrowser $httpBrowser;

    public function __construct(
        private EntityManagerInterface                       $entityManager,
        private HttpClientInterface                          $animeAnimeworldClient,
        #[Autowire('%anime.temp_folder%')] private string    $tempFolder,
        #[Autowire('%anime.animeworld.url%')] private string $websiteUrl)
    {
        $this->httpBrowser = new HttpBrowser($this->animeAnimeworldClient);
    }

    /**
     * Fetch page from url and create a crawler
     * @param string $url episode url
     * @return Crawler page
     */
    private function fetchPage(string $url): Crawler
    {
        $crawler = $this->httpBrowser->request('GET', $this->getWebsiteUrl() . $url);
        $response = $this->httpBrowser->getResponse();
        if ($response->getStatusCode() === 202) {
            if (!preg_match('/(SecurityAW-[^=]+)=([^;]+)/', $response->getContent(), $matches)) {
                throw new Exception("Error fetching page from AnimeWorld. Cookie SecurityAW-XX not found.");
            }
            $this->httpBrowser->getCookieJar()->set(new Cookie(trim($matches[1]), trim($matches[2])));
            $crawler = $this->httpBrowser->request('GET', $this->getWebsiteUrl() . $url);
            $response = $this->httpBrowser->getResponse();
        }
        if ($response->getStatusCode() !== 200) {
            throw new Exception("Error fetching page from AnimeWorld. Http code = " . $response->getStatusCode());
        }
        return $crawler;
    }

    /**
     * Add download url e file to episode object
     * @param Crawler $crawler anime page
     * @param EpisodeDownload $episode episode object
     * @return void
     */
    private function scrapeEpisodeFile(Crawler $crawler, EpisodeDownload $episode): void
    {
        $dlUrl = $crawler->filter("#downloadLink")->first()->attr("href");
        $episode->setDownloadUrl(str_replace("download-file.php?id=", "", $dlUrl));
        $episode->setFile(substr($dlUrl, strrpos($dlUrl, '/') + 1));
    }

    /**
     * Get id from anime page and button id
     * @param Crawler $crawler anime page
     * @param string $buttonId button id
     * @return int|null id
     */
    private function scrapeIdFromButton(Crawler $crawler, string $buttonId): ?int
    {
        $btn = $crawler->filter("#" . $buttonId);
        if ($btn->count() !== 1) {
            return null;
        }
        $link = $btn->first()->attr("href");
        $id = substr($link, strrpos($link, '/') + 1);
        return intval($id);
    }

    private function processFolder(?int $malId): string
    {
        $folder = $this->entityManager->getRepository(SeasonFolder::class)->find($malId);
        if ($folder) {
            return $folder->getFolder();
        }
        return $this->tempFolder;
    }

    /**
     * Create episode object from anime page and data
     * @param Crawler $globalCrawler global anime page
     * @param Crawler $itemCrawler anime page item
     * @param string $folder folder
     * @param int|null $malId MyAnimeList id
     * @param int|null $alId AnimeList id
     * @return EpisodeDownload
     * @throws Exception
     */
    private function getEpisodeObject(Crawler $globalCrawler, Crawler $itemCrawler, string $folder, ?int $malId, ?int $alId): EpisodeDownload
    {
        $episode = (new EpisodeDownload())
            ->setServiceName(self::getServiceName())
            ->setEpisodeUrl($itemCrawler->attr("href"))
            ->setEpisode($itemCrawler->attr("data-episode-num"))
            ->setFolder($folder)
            ->setMalId($malId)
            ->setAlId($alId);
        if ($itemCrawler->matches(".active")) {
            $this->scrapeEpisodeFile($globalCrawler, $episode);
        } else {
            $episodeCrawler = $this->fetchPage($episode->getEpisodeUrl());
            $this->scrapeEpisodeFile($episodeCrawler, $episode);
        }
        return $episode;
    }

    /**
     * @inheritDoc
     */
    public function createEpisodeDownloads(EpisodeDownloadRequest $downloadReq): array
    {
        $globalCrawler = $this->fetchPage($downloadReq->getUrlPath());
        $malId = $this->scrapeIdFromButton($globalCrawler, 'mal-button');
        if ($downloadReq->isFilter()) {
            $anime = $this->entityManager->getRepository(ListAnime::class)->find($malId);
            if (!$anime) {
                throw new CacheAnimeNotFoundException($malId);
            }
        }

        $alId = $this->scrapeIdFromButton($globalCrawler, 'anilist-button');
        $folder = $this->processFolder($malId);

        $episodes = [];
        $items = $globalCrawler->filter("div.server.active ul.episodes.range li.episode a" . ($downloadReq->isAll() ? "" : ".active"));
        foreach ($items as $item) {
            $episode = $this->getEpisodeObject($globalCrawler, new Crawler($item), $folder, $malId, $alId);
            if ($downloadReq->isSave()) {
                $this->entityManager->persist($episode);
            }
            $episodes[] = $episode;
        }
        if ($downloadReq->isSave()) {
            $this->entityManager->flush();
        }
        return $episodes;
    }

    /**
     * @inheritDoc
     */
    public function refreshDownloadUrl(EpisodeDownload $episode): void
    {
        $episodeCrawler = $this->fetchPage($episode->getEpisodeUrl());
        $this->scrapeEpisodeFile($episodeCrawler, $episode);
    }

    /**
     * @inheritDoc
     */
    public function getWebsiteUrl(): string
    {
        return $this->websiteUrl;
    }

    public static function getServiceName(): string
    {
        return 'animeworld';
    }
}