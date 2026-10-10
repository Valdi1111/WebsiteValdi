<?php

namespace App\AnimeBundle\Service\Tracker;

use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Exception\CacheRefreshException;
use App\AnimeBundle\Model\AlListAnime;
use App\AnimeBundle\Model\AlListManga;
use App\AnimeBundle\Repository\ListAnimeRepository;
use App\AnimeBundle\Repository\ListMangaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag(name: 'anime.tracker', attributes: ['key' => self::TRACKER_NAME])]
readonly class AniListTracker extends AbstractAnimeTracker
{
    public const string TRACKER_NAME = 'anilist';
    private const string API_URL = '/';

    private const string GRAPHQL_QUERY = <<<'GRAPHQL'
    query ($userName: String, $type: MediaType) {
      MediaListCollection(userName: $userName, type: $type) {
        lists {
          entries {
            mediaId
            status
            media {
              id
              title {
                romaji
                english
              }
              format
              countryOfOrigin
              episodes
              chapters
              volumes
              isAdult
            }
          }
        }
      }
    }
    GRAPHQL;

    public function __construct(
        #[Target('anime.cache')]
        private LoggerInterface        $logger,
        private EntityManagerInterface $entityManager,
        private ListAnimeRepository    $animeRepository,
        private ListMangaRepository    $mangaRepository,
        #[Target('anime.anilist.client')]
        HttpClientInterface            $httpClient,
        private DenormalizerInterface  $denormalizer,
        private ObjectMapperInterface  $objectMapper,
        #[Autowire(param: 'anime.anilist.user')]
        private string                 $user,
    ) {
        parent::__construct($httpClient);
    }

    public static function getTrackerName(): string
    {
        return self::TRACKER_NAME;
    }

    public function existsInAnimeCache(int $id): bool
    {
        return $this->animeRepository->findOneBy(['id' => $id, 'tracker' => self::TRACKER_NAME]) !== null;
    }

    public function existsInMangaCache(int $id): bool
    {
        return $this->mangaRepository->findOneBy(['id' => $id, 'tracker' => self::TRACKER_NAME]) !== null;
    }

    /**
     * @template T of object
     *
     * @param string $type
     * @param class-string $denormalizeClass
     * @param class-string<T> $class
     * @return T[]
     */
    private function refreshCache(string $type, string $denormalizeClass, string $class): array
    {
        $this->logger->info("Refreshing $type cache for " . self::TRACKER_NAME . "...");

        try {
            $response = $this->httpClient->request(Request::METHOD_POST, self::API_URL, [
                'json' => [
                    'query' => self::GRAPHQL_QUERY,
                    'variables' => [
                        'userName' => $this->user,
                        'type' => strtoupper($type),
                    ],
                ],
            ]);

            if ($response->getStatusCode() !== Response::HTTP_OK) {
                throw new \RuntimeException("Error fetching data from AniList API (Status {$response->getStatusCode()}).");
            }

            $data = $response->toArray();
            $lists = $data['data']['MediaListCollection']['lists'] ?? [];
        } catch (\Throwable $e) {
            throw new CacheRefreshException("$type (anilist)", $e);
        }

        // Deduplicate entries by mediaId across different user status/custom lists
        $uniqueEntries = [];
        foreach ($lists as $list) {
            foreach ($list['entries'] as $entry) {
                $mediaId = (int) $entry['media']['id'];
                if (!isset($uniqueEntries[$mediaId])) {
                    $uniqueEntries[$mediaId] = $entry;
                }
            }
        }

        $denormalizedList = $this->denormalizer->denormalize(
            array_values($uniqueEntries),
            $denormalizeClass . '[]'
        );

        // Wrap delete and insert in a single transaction to prevent empty cache state on failures
        return $this->entityManager->wrapInTransaction(function () use ($class, $denormalizedList, $type) {
            // Only remove existing entries for this specific tracker
            $this->entityManager->getRepository($class)
                ->createQueryBuilder('e')
                ->delete()
                ->where('e.tracker = :tracker')
                ->setParameter('tracker', self::TRACKER_NAME)
                ->getQuery()
                ->execute();

            $cacheList = [];
            foreach ($denormalizedList as $denormalizedItem) {
                /** @var ListAnime|ListManga $cacheItem */
                $cacheItem = $this->objectMapper->map($denormalizedItem);
                $cacheItem->setTracker(self::TRACKER_NAME);

                $this->entityManager->persist($cacheItem);
                $cacheList[] = $cacheItem;
            }

            $this->entityManager->flush();
            $this->logger->info("Successfully refreshed " . self::TRACKER_NAME . " $type cache! (found " . count($cacheList) . " entries)");

            return $cacheList;
        });
    }

    public function refreshAnimeCache(): array
    {
        return $this->refreshCache(
            'anime',
            AlListAnime::class,
            ListAnime::class
        );
    }

    public function refreshMangaCache(): array
    {
        return $this->refreshCache(
            'manga',
            AlListManga::class,
            ListManga::class
        );
    }

    public function getAnimeUrl(int $id): string
    {
        return "https://anilist.co/anime/{$id}";
    }

    public function getMangaUrl(int $id): string
    {
        return "https://anilist.co/manga/{$id}";
    }
}
