<?php

namespace App\AnimeBundle\Service\Provider;

use App\AnimeBundle\Exception\ScrapeParsingException;
use App\AnimeBundle\Model\EpisodeDownloadRequest;
use App\AnimeBundle\Model\ScrapedEpisode;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias('anime.provider.' . self::PROVIDER_NAME)]
#[AsAlias(AnimeProviderInterface::class, target: self::PROVIDER_NAME)]
#[AutoconfigureTag(name: 'anime.provider', attributes: ['key' => self::PROVIDER_NAME])]
readonly class AnimeWorldProvider extends AbstractAnimeProvider
{
    public const string PROVIDER_NAME = "animeworld";

    public const string URL_REGEX = "/^\/play\/(?<animeLink>.+?)\.(?<animeIdentifier>[^\/]+)\/(?<episodeToken>[^\/]+)$/";

    public function __construct(
        #[Target('anime.animeworld.client')]
        HttpClientInterface         $httpClient,
        #[Target('node_services.client')]
        private HttpClientInterface $nodeServicesClient,
        #[Autowire(param: 'anime.animeworld.url_regex')]
        string                      $websiteUrlRegex,
        #[Autowire(param: 'anime.animeworld.url')]
        string                      $websiteUrl,
    )
    {
        parent::__construct($httpClient, $websiteUrl, $websiteUrlRegex);
    }

    /**
     * @inheritDoc
     */
    public function supports(string $url): bool
    {
        return preg_match($this->getWebsiteUrlRegex(), $url);
    }

    /**
     * @inheritDoc
     */
    public function fetchLatestEpisodeUrls(): array
    {
        $crawler = $this->fetchPage();

        $urlPaths = $crawler
            ->filter("#main .widget-body .content[data-name='sub'] .film-list > .item > .inner > a.name")
            ->each(fn(Crawler $node) => $node->attr("href"));

        return array_reverse($urlPaths);
    }

    /**
     * Add download url and file to episode metadata
     *
     * @param string $episodeUrl
     * @return array{0: string, 1: string} [downloadUrl, filename]
     * @throws ScrapeParsingException
     */
    private function resolveEpisodeFile(string $episodeUrl): array
    {
        if (!preg_match(self::URL_REGEX, $episodeUrl, $matches)) {
            throw new ScrapeParsingException(self::PROVIDER_NAME, "Episode URL does not match expected pattern: {$episodeUrl}");
        }

        $episodeToken = $matches['episodeToken'];

        try {
            $response = $this->nodeServicesClient->request(Request::METHOD_GET, "/animeworld/extract-url", [
                'query' => [
                    'url' => "{$this->getWebsiteUrl()}/api/episode/serverPlayerAnimeWorld?id=$episodeToken"
                ]
            ]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new ScrapeParsingException(self::PROVIDER_NAME, "Failed to resolve download URL from Node service: {$e->getMessage()}", $e);
        }

        if (empty($data['url'])) {
            throw new ScrapeParsingException(self::PROVIDER_NAME, "Node extraction service returned an empty URL for token: {$episodeToken}");
        }

        $dlUrl = $data['url'];
        $cleanDownloadUrl = str_replace("download-file.php?id=", "", $dlUrl);
        $filename = substr($dlUrl, strrpos($dlUrl, '/') + 1);

        return [$cleanDownloadUrl, $filename];
    }

    /**
     * Get id from anime page and button id
     *
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

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @inheritDoc
     */
    public function scrapeEpisodes(EpisodeDownloadRequest $downloadReq): array
    {
        // TODO add option to download missed episodes
        // TODO add option to download specific episodes
        $globalCrawler = $this->fetchPage($downloadReq->getUrlPath());
        $malId = $this->scrapeIdFromButton($globalCrawler, 'mal-button');
        $alId = $this->scrapeIdFromButton($globalCrawler, 'anilist-button');

        $episodes = [];
        $items = $globalCrawler->filter("div.server.active ul.episodes.range li.episode a" . ($downloadReq->isAll() ? "" : ".active"));

        foreach ($items as $item) {
            $itemCrawler = new Crawler($item);
            $episodeUrl = $itemCrawler->attr("href");

            [$downloadUrl, $filename] = $this->resolveEpisodeFile($episodeUrl);

            $episodes[] = new ScrapedEpisode()
                ->setProvider(self::PROVIDER_NAME)
                ->setEpisodeUrl($episodeUrl)
                ->setEpisodeNumber($itemCrawler->attr("data-episode-num"))
                ->setDownloadUrl($downloadUrl)
                ->setFilename($filename)
                ->setMalId($malId)
                ->setAlId($alId);
        }

        return $episodes;
    }

    /**
     * @inheritDoc
     */
    public function extractDownloadUrl(string $episodeUrl): string
    {
        [$downloadUrl] = $this->resolveEpisodeFile($episodeUrl);
        return $downloadUrl;
    }

    /**
     * @inheritDoc
     */
    public static function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }
}
