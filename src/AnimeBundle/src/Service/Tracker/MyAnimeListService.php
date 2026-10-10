<?php

namespace App\AnimeBundle\Service\Tracker;

use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Exception\CacheRefreshException;
use App\AnimeBundle\Model\MalListAnime;
use App\AnimeBundle\Model\MalListManga;
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
readonly class MyAnimeListService implements AnimeTrackerInterface
{
    public const string TRACKER_NAME = 'myanimelist';
    public const string FETCH_URL = '/v2/users/%1$s/%2$slist?nsfw=true&limit=%3$d&fields=%4$s';
    public const int LIMIT = 1000;

    public function __construct(
        #[Target('anime.cache')]
        private LoggerInterface        $logger,
        private EntityManagerInterface $entityManager,
        private ListAnimeRepository    $animeRepository,
        private ListMangaRepository    $mangaRepository,
        #[Target('anime.myanimelist.client')]
        private HttpClientInterface    $httpClient,
        private DenormalizerInterface  $denormalizer,
        private ObjectMapperInterface  $objectMapper,
        #[Autowire(param: 'anime.myanimelist.user')]
        private string                 $user,
    ) {
    }

    public static function getTrackerName(): string
    {
        return self::TRACKER_NAME;
    }

    public function existsInAnimeCache(int $id): bool
    {
        return $this->animeRepository->findOneBy(['id' => $id, 'provider' => self::TRACKER_NAME]) !== null;
    }

    public function existsInMangaCache(int $id): bool
    {
        return $this->mangaRepository->findOneBy(['id' => $id, 'provider' => self::TRACKER_NAME]) !== null;
    }

    /**
     * @template T of object
     *
     * @param string $type
     * @param string[] $fields string[]
     * @param class-string $denormalizeClass
     * @param class-string<T> $class
     * @return T[]
     */
    private function refreshCache(string $type, array $fields, string $denormalizeClass, string $class): array
    {
        $this->logger->info("Refreshing $type cache for " . self::TRACKER_NAME . "...");
        $denormalizedList = [];
        try {
            $next = sprintf(self::FETCH_URL, $this->user, $type, self::LIMIT, implode(',', $fields));
            while ($next) {
                $response = $this->httpClient->request(Request::METHOD_GET, $next);
                if ($response->getStatusCode() !== Response::HTTP_OK) {
                    throw new \RuntimeException("Error fetching list from MyAnimeList. (Http code {$response->getStatusCode()})");
                }
                $content = $response->toArray();
                $next = $content['paging']['next'] ?? null;
                $denormalizedList = array_merge($denormalizedList, $this->denormalizer->denormalize($content['data'], $denormalizeClass . '[]'));
            }
        } catch (\Throwable $e) {
            throw new CacheRefreshException($type, $e);
        }

        // Wrap delete and insert in a single transaction to prevent empty cache state on failures
        return $this->entityManager->wrapInTransaction(function () use ($class, $denormalizedList, $type) {
            // Only remove existing entries for this specific provider
            $this->entityManager->getRepository($class)
                ->createQueryBuilder('e')
                ->delete()
                ->where('e.provider = :provider')
                ->setParameter('provider', self::TRACKER_NAME)
                ->getQuery()
                ->execute();

            $cacheList = [];
            foreach ($denormalizedList as $denormalizedItem) {
                /** @var ListAnime|ListManga $cacheItem */
                $cacheItem = $this->objectMapper->map($denormalizedItem);
                $cacheItem->setProvider(self::TRACKER_NAME);

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
            ['id', 'title', 'alternative_titles', 'nsfw', 'media_type', 'num_episodes', 'list_status'],
            MalListAnime::class,
            ListAnime::class
        );
    }

    public function refreshMangaCache(): array
    {
        return $this->refreshCache(
            'manga',
            ['id', 'title', 'alternative_titles', 'nsfw', 'media_type', 'num_volumes', 'num_chapters', 'list_status'],
            MalListManga::class,
            ListManga::class
        );
    }
}
