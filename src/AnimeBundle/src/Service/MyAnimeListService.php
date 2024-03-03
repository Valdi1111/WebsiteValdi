<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListAnimeStatus;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Entity\ListMangaStatus;
use App\AnimeBundle\Exception\CacheRefreshException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCronTask('@midnight', method: 'refreshAnimeCache')]
#[AsCronTask('@midnight', method: 'refreshMangaCache')]
class MyAnimeListService
{
    const FETCH_URL = 'https://api.myanimelist.net/v2/users/%1$s/%2$slist?nsfw=true&fields=list_status&limit=%3$d';
    const USER = 'Valdi_1111';
    const LIMIT = 1000;

    public function __construct(private readonly LoggerInterface $animeLogger, private readonly EntityManagerInterface $animeEntityManager, private readonly HttpClientInterface $malApiClient)
    {
    }

    /**
     * @param $type string
     * @param $class class-string
     * @param $statusClass class-string
     * @return ListAnime[]|ListManga[]|null
     */
    private function refreshCache(string $type, string $class, string $statusClass): ?array
    {
        $this->animeLogger->info("Refreshing $type cache...");
        $newList = [];
        $next = sprintf(self::FETCH_URL, self::USER, $type, self::LIMIT);
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
                    $newList[] = (new $class)
                        ->setId($data['node']['id'])
                        ->setTitle($data['node']['title'])
                        ->setStatus($statusClass::tryFrom($data['list_status']['status']));
                }
            }
        } catch (\Throwable $e) {
            throw new CacheRefreshException($type, $e);
        }
        $oldList = $this->animeEntityManager->getRepository($class)->findAll();
        foreach ($oldList as $anime) {
            $this->animeEntityManager->remove($anime);
        }
        $this->animeEntityManager->flush();
        foreach ($newList as $anime) {
            $this->animeEntityManager->persist($anime);
        }
        $this->animeEntityManager->flush();
        $this->animeLogger->info("Successfully refreshed $type cache! (found (" . count($newList) . ") entries)");
        return $newList;
    }

    /**
     * @return ?ListAnime[]
     */
    public function refreshAnimeCache(): ?array
    {
        return $this->refreshCache('anime', ListAnime::class, ListAnimeStatus::class);
    }

    /**
     * @return ?ListManga[]
     */
    public function refreshMangaCache(): ?array
    {
        return $this->refreshCache('manga', ListManga::class, ListMangaStatus::class);
    }

}