<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListAnimeStatus;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Entity\ListMangaStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MyAnimeListService
{
    private string $user = "Valdi_1111";
    private int $limit = 1000;

    public function __construct(private readonly EntityManagerInterface $animeEntityManager, private readonly HttpClientInterface $malApiClient)
    {
    }

    /**
     * @param $urlParameter string
     * @param $class class-string
     * @param $statusClass class-string
     * @return ListAnime[]|ListManga[]|null
     */
    private function refreshCache(string $urlParameter, string $class, string $statusClass): ?array
    {
        $newList = [];
        $next = "https://api.myanimelist.net/v2/users/$this->user/$urlParameter?nsfw=true&fields=list_status&limit=$this->limit";
        while ($next) {
            $response = $this->malApiClient->request('GET', $next);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception("Error fetching list from MyAnimeList. (Http code {$response->getStatusCode()})");
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
        if (count($newList) > 0) {
            $oldList = $this->animeEntityManager->getRepository($class)->findAll();
            foreach ($oldList as $anime) {
                $this->animeEntityManager->remove($anime);
            }
            $this->animeEntityManager->flush();
            foreach ($newList as $anime) {
                $this->animeEntityManager->persist($anime);
            }
            $this->animeEntityManager->flush();
        }
        return $newList;
    }

    /**
     * @return ?ListAnime[]
     */
    public function refreshAnimeCache(): ?array
    {
        return $this->refreshCache('animelist', ListAnime::class, ListAnimeStatus::class);
    }

    /**
     * @return ?ListManga[]
     */
    public function refreshMangaCache(): ?array
    {
        return $this->refreshCache('mangalist', ListManga::class, ListMangaStatus::class);
    }

}