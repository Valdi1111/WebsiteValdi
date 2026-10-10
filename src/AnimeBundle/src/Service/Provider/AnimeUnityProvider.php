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
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias('anime.provider.' . self::PROVIDER_NAME)]
#[AsAlias(AnimeProviderInterface::class, target: self::PROVIDER_NAME)]
#[AutoconfigureTag(name: 'anime.provider', attributes: ['key' => self::PROVIDER_NAME])]
readonly class AnimeUnityProvider extends AbstractAnimeProvider
{
    public const string PROVIDER_NAME = "animeunity";

    public function __construct(
        #[Target('anime.animeunity.client')]
        HttpClientInterface $httpClient,
        #[Autowire(param: 'anime.animeunity.url_regex')]
        string $websiteUrlRegex,
        #[Autowire(param: 'anime.animeunity.url')]
        string $websiteUrl,
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
        return [];
    }

    /**
     * Extract download url and file name from the embed page
     *
     * @param string $embedUrl
     * @return array{0: string, 1: string} [downloadUrl, filename]
     * @throws ScrapeParsingException
     */
    private function scrapeEpisodeFile(string $embedUrl): array
    {
        $embedCrawler = $this->fetchPage($embedUrl);
        $scripts = $embedCrawler->filter("script");
        $data = [];

        foreach ($scripts as $script) {
            $text = $script->textContent;
            if (!str_contains($text, "window.")) {
                continue;
            }

            // Normalize line breaks to spaces for cross-line token analysis
            $cleanText = trim(preg_replace("/\r\n|\n|\r/", " ", $text));

            // 1. Match direct scalar assignments (e.g. window.downloadUrl = 'https://...'; or without trailing semicolon)
            if (preg_match_all("/window\.(?<key>[a-zA-Z0-9_]+)\s*=\s*['\"](?<value>[^'\"]+)['\"]\s*;?/i", $cleanText, $stringMatches, PREG_SET_ORDER)) {
                foreach ($stringMatches as $match) {
                    $data[$match['key']] = trim($match['value']);
                }
            }

            // 2. Match boolean or numeric scalar assignments (e.g. window.canPlayFHD = true;)
            if (preg_match_all("/window\.(?<key>[a-zA-Z0-9_]+)\s*=\s*(?<value>true|false|\d+)\s*;?/i", $cleanText, $literalMatches, PREG_SET_ORDER)) {
                foreach ($literalMatches as $match) {
                    $val = trim($match['value']);
                    $data[$match['key']] = match ($val) {
                        'true' => true,
                        'false' => false,
                        default => is_numeric($val) ? (int) $val : $val,
                    };
                }
            }

            // 3. Match array assignments (e.g. window.streams = [...];)
            if (preg_match_all("/window\.(?<key>[a-zA-Z0-9_]+)\s*=\s*(?<value>\[.*?\])\s*;?/i", $cleanText, $arrayMatches, PREG_SET_ORDER)) {
                foreach ($arrayMatches as $match) {
                    $key = $match['key'];
                    $rawJson = trim($match['value']);
                    if (json_validate($rawJson)) {
                        $data[$key] = json_decode($rawJson, true);
                    }
                }
            }

            // 4. Match object assignments (e.g. window.video = { ... };)
            if (preg_match_all("/window\.(?<key>[a-zA-Z0-9_]+)\s*=\s*(?<value>\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})\s*;?/i", $cleanText, $objMatches, PREG_SET_ORDER)) {
                foreach ($objMatches as $match) {
                    $key = $match['key'];
                    $rawJson = trim($match['value']);

                    if (json_validate($rawJson)) {
                        $data[$key] = json_decode($rawJson, true);
                    } else {
                        // Remove trailing commas before closing braces
                        $normalized = preg_replace("/,\s*}/", "}", $rawJson);
                        // Convert single quoted or unquoted keys to double quoted keys
                        $normalized = preg_replace("/([{,]\s*)(['\"])?([a-zA-Z0-9_]+)\2(\s*:)/", '$1"$3"$4', $normalized);
                        // Convert single quoted string values to double quoted string values
                        $normalized = preg_replace("/:\s*'([^']*)'/", ': "$1"', $normalized);

                        if (json_validate($normalized)) {
                            $data[$key] = json_decode($normalized, true);
                        }
                    }
                }
            }
        }

        if (empty($data['downloadUrl'])) {
            throw new ScrapeParsingException(self::PROVIDER_NAME, "Failed to scrape downloadUrl parameter from $embedUrl");
        }

        $downloadUrl = $data['downloadUrl'];
        $filename = null;

        // Resolution strategy 1: extract 'filename' query parameter from the download URL
        $parsedUrl = parse_url($downloadUrl);
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            if (!empty($queryParams['filename'])) {
                $filename = $queryParams['filename'];
            }
        }

        // Resolution strategy 2: fallback to window.video.name or window.video.filename if present
        if (empty($filename) && !empty($data['video'])) {
            $filename = !empty($data['video']['name']) ? $data['video']['name'] : ($data['video']['filename'] ?? null);
        }

        // Resolution strategy 3: fallback to the last segment of the download URL path
        if (empty($filename) && !empty($parsedUrl['path'])) {
            $filename = basename($parsedUrl['path']);
        }

        if (empty($filename)) {
            throw new ScrapeParsingException(self::PROVIDER_NAME, "Failed to resolve filename for embed $embedUrl");
        }

        $cleanFilename = str_replace(' ', '_', $filename);

        return [$downloadUrl, $cleanFilename];
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

    /**
     * Build ScrapedEpisode object from page payload
     *
     * @param array $pageData
     * @param int $episodeKey
     * @return ScrapedEpisode
     * @throws ScrapeParsingException
     */
    private function buildScrapedEpisode(array $pageData, int $episodeKey): ScrapedEpisode
    {
        $episodeData = &$pageData['episodes'][$episodeKey];
        $episodeUrl = "/anime/{$pageData['anime']['id']}-{$pageData['anime']['slug']}/{$episodeData['id']}";

        if ($episodeData['id'] != $pageData['episode']['id']) {
            $episodeCrawler = $this->fetchPage($episodeUrl);
            $pageData = $this->scrapeEpisodeDataFromPage($episodeCrawler);
            if (!$pageData) {
                throw new ScrapeParsingException(self::PROVIDER_NAME, "Could not scrape video-player data from $episodeUrl");
            }
        }

        [$downloadUrl, $filename] = $this->scrapeEpisodeFile($pageData['embed_url']);

        return new ScrapedEpisode()
            ->setProvider(self::PROVIDER_NAME)
            ->setEpisodeUrl($episodeUrl)
            ->setEpisodeNumber((string) $episodeData['number'])
            ->setDownloadUrl($downloadUrl)
            ->setFilename($filename)
            ->setMalId($pageData['anime']['mal_id'] ?? null)
            ->setAlId($pageData['anime']['anilist_id'] ?? null);
    }

    /**
     * @inheritDoc
     */
    public function scrapeEpisodes(EpisodeDownloadRequest $downloadReq): array
    {
        $globalCrawler = $this->fetchPage($downloadReq->getUrlPath());
        $pageData = $this->scrapeEpisodeDataFromPage($globalCrawler);

        if (!$pageData) {
            throw new ScrapeParsingException(self::PROVIDER_NAME, "Failed to parse anime page structure at: " . $downloadReq->getUrlPath());
        }

        // TODO scaricare tutti gli episodi solo se $downloadReq->isAll()
        $episodes = [];
        foreach ($pageData['episodes'] as $episodeKey => $episodeData) {
            $episodes[] = $this->buildScrapedEpisode($pageData, $episodeKey);
        }

        return $episodes;
    }

    /**
     * @inheritDoc
     */
    public function extractDownloadUrl(string $episodeUrl): string
    {
        $episodeCrawler = $this->fetchPage($episodeUrl);
        $pageData = $this->scrapeEpisodeDataFromPage($episodeCrawler);

        if (!$pageData || empty($pageData['embed_url'])) {
            throw new ScrapeParsingException(self::PROVIDER_NAME, "Cannot retrieve embed_url for $episodeUrl");
        }

        [$downloadUrl] = $this->scrapeEpisodeFile($pageData['embed_url']);
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
