<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\SeasonFolder;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\UnhandledWebsiteException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnimeWorldService
{

    public function __construct(private readonly EntityManagerInterface $animeEntityManager, private readonly HttpClientInterface $awClient, private readonly ParameterBagInterface $params)
    {
    }

    /**
     * Get anime page from episode url
     * @param string $episodeUrl episode url
     * @return Crawler anime page
     */
    private function fetchEpisodePage(string $episodeUrl): Crawler
    {
        $response = $this->awClient->request('GET', $this->params->get('anime.aw.url') . $episodeUrl);
        if ($response->getStatusCode() !== 200) {
            throw new Exception("Error fetching page from AnimeWorld. Http code = " . $response->getStatusCode());
        }
        $content = $response->getContent();
        return new Crawler($content);
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
        $folder = $this->animeEntityManager->getRepository(SeasonFolder::class)->findOneBy(['id' => $malId]);
        if ($folder) {
            return $folder->getFolder();
        }
        return $this->params->get('anime.temp_folder');
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
            ->setEpisodeUrl($itemCrawler->attr("href"))
            ->setEpisode($itemCrawler->attr("data-episode-num"))
            ->setFolder($folder)
            ->setMalId($malId)
            ->setAlId($alId);
        if ($itemCrawler->matches(".active")) {
            $this->scrapeEpisodeFile($globalCrawler, $episode);
        } else {
            $episodeCrawler = $this->fetchEpisodePage($itemCrawler->attr("href"));
            $this->scrapeEpisodeFile($episodeCrawler, $episode);
        }
        return $episode;
    }

    /**
     * @param string $url anime url (with hostname)
     * @param bool $allEpisodes query all episodes
     * @param bool $filter filter if not present in MyAnimeList cache
     * @param bool $save save to database
     * @return EpisodeDownload[]
     * @throws UnhandledWebsiteException
     * @throws CacheAnimeNotFoundException
     * @throws Exception
     */
    public function createEpisodeDownloads(string $url, bool $allEpisodes = false, bool $filter = true, bool $save = true): array
    {
        if (!str_starts_with($url, $this->params->get('anime.aw.url'))) {
            throw new UnhandledWebsiteException();
        }
        $episodeUrl = preg_replace("/.*\/\/[^\/]*/", "", $url);
        $globalCrawler = $this->fetchEpisodePage($episodeUrl);
        $malId = $this->scrapeIdFromButton($globalCrawler, 'mal-button');
        if ($filter) {
            $anime = $this->animeEntityManager->getRepository(ListAnime::class)->findOneBy(['id' => $malId]);
            if (!$anime) {
                throw new CacheAnimeNotFoundException($malId);
            }
        }

        $alId = $this->scrapeIdFromButton($globalCrawler, 'anilist-button');
        $folder = $this->processFolder($malId);

        $episodes = [];
        $items = $globalCrawler->filter("div.server.active ul.episodes.range li.episode a" . ($allEpisodes ? "" : ".active"));
        foreach ($items as $item) {
            $itemCrawler = new Crawler($item);
            $episode = $this->getEpisodeObject($globalCrawler, $itemCrawler, $folder, $malId, $alId);
            if ($save) {
                $this->animeEntityManager->persist($episode);
            }
            $episodes[] = $episode;
        }
        if ($save) {
            $this->animeEntityManager->flush();
        }
        return $episodes;
    }

}