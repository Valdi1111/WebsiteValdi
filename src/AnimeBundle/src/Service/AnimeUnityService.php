<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeDownloadRequest;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\SeasonFolder;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias('animeunity.anime.downloader')]
#[AsAlias('App\AnimeBundle\Service\AnimeDownloaderInterface $animeUnityDownloader')]
readonly class AnimeUnityService implements AnimeDownloaderInterface
{

    public function __construct(
        private EntityManagerInterface                       $entityManager,
        private HttpClientInterface                          $animeAnimeunityClient,
        #[Autowire('%anime.temp_folder%')] private string    $tempFolder,
        #[Autowire('%anime.animeunity.url%')] private string $websiteUrl)
    {
    }

    /**
     * Fetch page from url and create a crawler
     * @param string $url episode url
     * @return Crawler page
     */
    private function fetchPage(string $url): Crawler
    {
        $response = $this->animeAnimeunityClient->request('GET', $url);
        if ($response->getStatusCode() !== 200) {
            throw new Exception("Error fetching page from AnimeUnity. Http code = " . $response->getStatusCode());
        }
        return new Crawler($response->getContent());
    }

    /**
     * Add download url e file to episode object
     * @param string $embedUrl
     * @param EpisodeDownload $episode episode object
     * @return void
     */
    private function scrapeEpisodeFile(string $embedUrl, EpisodeDownload $episode): void
    {
        $embedCrawler = $this->fetchPage($embedUrl);
        $scripts = $embedCrawler->filter("script");
        $data = [];
        foreach ($scripts as $script) {
            $text = $script->textContent;
            if (!str_contains($text, "window.")) {
                continue;
            }
            $text = preg_replace("/(\r\n|\n|\r)/m", "", $text);
            $text = trim($text);

            $matches = [];
            preg_match_all("/window\.(?<key>\w+)\s*=\s*(?<value>\{[^;]*}|\[[^;]*]|true|false|'.*?'|\".*?\"|\d+)/", $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $key = trim($match['key']);
                $value = trim($match['value']);
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                if (json_validate($value)) {
                    $value = json_decode($value, true);
                } else {
                    // Rimuove tutte le virgole prima delle parentesi graffe (anche se ci sono spazi vuoti)
                    $input = preg_replace("/,\s*}/", "}", $value);
                    // Inserisce le virgolette nelle chiavi del json
                    $input = preg_replace("/\s*(['\"])?([a-z0-9A-Z_]+)(?<!https|http)(['\"])?\s*:\s*/", '"$2": ', $input);
                    // Inserisce le virgolette nei valori del json
                    $input = preg_replace("/: (['\"])?([a-z0-9A-Z_:\/.]+)(['\"])?/", ': "$2"', $input);
                    if (json_validate($input)) {
                        $value = json_decode($input, true);
                    }
                }
                $data[$key] = $value;
            }
        }
        $episode->setDownloadUrl($data['downloadUrl']);
        $episode->setFile(str_replace(' ', '_', $data['video']['name']));
    }

    private function scrapeEpisodeDataFromPage(Crawler $crawler): ?array
    {
        $videoPlayer = $crawler->filter("video-player");
        if ($videoPlayer->count() !== 1) {
            return null;
        }
        $data = [];
        foreach ($videoPlayer->first()->getNode(0)->attributes as $attr) {
            $data[$attr->name] = $attr->value;
            if (json_validate($attr->value)) {
                $data[$attr->name] = json_decode($attr->value, true);
            }
        }
        return $data;
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
     * Create episode object from anime page data
     * @param array $pageData page data
     * @param string $folder folder
     * @param int|null $malId MyAnimeList id
     * @param int|null $alId AnimeList id
     * @return EpisodeDownload
     * @throws Exception
     */
    private function getEpisodeObject(array $pageData, int $episodeKey, string $folder, ?int $malId, ?int $alId): EpisodeDownload
    {
        $episodeData = &$pageData['episodes'][$episodeKey];
        $episode = (new EpisodeDownload())
            ->setServiceName(self::getServiceName())
            ->setEpisodeUrl("/anime/{$pageData['anime']['id']}-{$pageData['anime']['slug']}/{$episodeData['id']}")
            ->setEpisode($episodeData['number'])
            ->setFolder($folder)
            ->setMalId($malId)
            ->setAlId($alId);
        if ($episodeData['id'] != $pageData['episode']['id']) {
            $episodeCrawler = $this->fetchPage($episode->getEpisodeUrl());
            $pageData = $this->scrapeEpisodeDataFromPage($episodeCrawler);
        }
        $this->scrapeEpisodeFile($pageData['embed_url'], $episode);
        return $episode;
    }

    /**
     * @inheritDoc
     */
    public function createEpisodeDownloads(EpisodeDownloadRequest $downloadReq): array
    {
        $globalCrawler = $this->fetchPage($downloadReq->getUrlPath());
        $pageData = $this->scrapeEpisodeDataFromPage($globalCrawler);

        $malId = $pageData['anime']['mal_id'];
        if ($downloadReq->isFilter()) {
            $anime = $this->entityManager->getRepository(ListAnime::class)->find($malId);
            if (!$anime) {
                throw new CacheAnimeNotFoundException($malId);
            }
        }

        $alId = $pageData['anime']['anilist_id'];
        $folder = $this->processFolder($malId);

        // TODO scaricare tutti gli episodi solo se $downloadReq->isAll()
        $episodes = [];
        foreach ($pageData['episodes'] as $episodeKey => $episodeData) {
            $episode = $this->getEpisodeObject($pageData, $episodeKey, $folder, $malId, $alId);
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
    public function getWebsiteUrl(): string
    {
        return $this->websiteUrl;
    }

    public static function getServiceName(): string
    {
        return 'animeunity';
    }
}