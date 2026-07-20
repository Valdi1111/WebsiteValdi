<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Entity\MalListAnime;
use App\AnimeBundle\Entity\MalListManga;
use App\AnimeBundle\Exception\CacheRefreshException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class MyAnimeListService
{
    const string FETCH_URL = 'https://api.myanimelist.net/v2/users/%1$s/%2$slist?nsfw=true&limit=%3$d&fields=%4$s';
    const string USER = 'Valdi_1111';
    const int LIMIT = 1000;

    public function __construct(
        private LoggerInterface        $animeCacheLogger,
        private EntityManagerInterface $entityManager,
        private HttpClientInterface    $animeMyanimelistClient,
        private DenormalizerInterface  $denormalizer,
        private ObjectMapperInterface  $objectMapper
    )
    {
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
        $this->animeCacheLogger->info("Refreshing $type cache...");
        $denormalizedList = [];
        try {
            $next = sprintf(self::FETCH_URL, self::USER, $type, self::LIMIT, implode(',', $fields));
            while ($next) {
                $response = $this->animeMyanimelistClient->request('GET', $next);
                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException("Error fetching list from MyAnimeList. (Http code {$response->getStatusCode()})");
                }
                $content = $response->toArray();
                $next = null;
                if (array_key_exists('next', $content['paging'])) {
                    $next = $content['paging']['next'];
                }
                $denormalizedList = array_merge($denormalizedList, $this->denormalizer->denormalize($content['data'], $denormalizeClass . '[]'));
            }
        } catch (\Throwable $e) {
            throw new CacheRefreshException($type, $e);
        }
        $this->entityManager->getRepository($class)
            ->createQueryBuilder('e')
            ->delete()
            ->getQuery()
            ->execute();
        $cacheList = [];
        foreach ($denormalizedList as $denormalizedItem) {
            $cacheItem = $this->objectMapper->map($denormalizedItem);
            $this->entityManager->persist($cacheItem);
            $cacheList[] = $cacheItem;
        }
        $this->entityManager->flush();
        $this->animeCacheLogger->info("Successfully refreshed $type cache! (found (" . count($cacheList) . ") entries)");
        return $cacheList;
    }

    /**
     * Refresh anime cache
     * @return ListAnime[]
     */
    public function refreshAnimeCache(): array
    {
        return $this->refreshCache(
            'anime',
            ['id', 'title', 'alternative_titles', 'nsfw', 'media_type', 'num_episodes', 'list_status'],
            MalListAnime::class,
            ListAnime::class
        );
    }

    /**
     * Refresh manga cache
     * @return ListManga[]
     */
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