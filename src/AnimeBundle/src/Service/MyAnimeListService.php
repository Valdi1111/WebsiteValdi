<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Exception\CacheRefreshException;
use App\AnimeBundle\Message\AnimeCacheRefreshNotification;
use App\AnimeBundle\Message\MangaCacheRefreshNotification;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCronTask('@midnight', method: 'scheduleRefreshAnimeCache')]
#[AsCronTask('@midnight', method: 'scheduleRefreshMangaCache')]
class MyAnimeListService
{
    const FETCH_URL = 'https://api.myanimelist.net/v2/users/%1$s/%2$slist?nsfw=true&limit=%3$d&fields=%4$s';
    const USER = 'Valdi_1111';
    const LIMIT = 1000;
    const FIELDS_ANIME = ['id', 'title', 'alternative_titles', 'nsfw', 'media_type', 'num_episodes', 'list_status'];
    const FIELDS_MANGA = ['id', 'title', 'alternative_titles', 'nsfw', 'media_type', 'num_volumes', 'num_chapters', 'list_status'];

    public function __construct(
        private readonly LoggerInterface        $animeLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface    $malApiClient,
        private readonly MessageBusInterface    $bus)
    {
    }

    /**
     * @param $type string
     * @param $fields string[]
     * @param $class class-string
     * @return ListAnime[]|ListManga[]
     */
    private function refreshCache(string $type, array $fields, string $class): array
    {
        $this->animeLogger->info("Refreshing $type cache...");
        $newList = [];
        $next = sprintf(self::FETCH_URL, self::USER, $type, self::LIMIT, implode(',', $fields));
        try {
            while ($next) {
                $response = $this->malApiClient->request('GET', $next);
                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException("Error fetching list from MyAnimeList. (Http code {$response->getStatusCode()})");
                }
                $content = $response->toArray();
                $next = null;
                if (array_key_exists('next', $content['paging'])) {
                    $next = $content['paging']['next'];
                }
                foreach ($content['data'] as $data) {
                    $newList[] = (new $class)->deserializeMal($data);
                }
            }
        } catch (\Throwable $e) {
            throw new CacheRefreshException($type, $e);
        }
        $this->entityManager->getRepository($class)
            ->createQueryBuilder('e')
            ->delete()
            ->getQuery()
            ->execute();
        foreach ($newList as $anime) {
            $this->entityManager->persist($anime);
        }
        $this->entityManager->flush();
        $this->animeLogger->info("Successfully refreshed $type cache! (found (" . count($newList) . ") entries)");
        return $newList;
    }

    /**
     * Refresh anime cache
     * @return ListAnime[]
     */
    public function refreshAnimeCache(): array
    {
        return $this->refreshCache('anime', self::FIELDS_ANIME, ListAnime::class);
    }

    /**
     * Refresh manga cache
     * @return ListManga[]
     */
    public function refreshMangaCache(): array
    {
        return $this->refreshCache('manga', self::FIELDS_MANGA, ListManga::class);
    }

    /**
     * Refresh anime cache async
     * @return void
     */
    public function scheduleRefreshAnimeCache(): void
    {
        $this->bus->dispatch(new AnimeCacheRefreshNotification());
    }

    /**
     * Refresh manga cache async
     * @return void
     */
    public function scheduleRefreshMangaCache(): void
    {
        $this->bus->dispatch(new MangaCacheRefreshNotification());
    }

}