<?php

namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeDownloadState;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\UnhandledWebsiteException;
use App\AnimeBundle\Message\EpisodeDownloadNotification;
use App\AnimeBundle\Repository\EpisodeDownloadRepository;
use App\AnimeBundle\Service\AnimeDownloaderInterface;
use App\AnimeBundle\Service\MyAnimeListService;
use App\CoreBundle\Controller\FileManagerTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use UnitEnum;

#[Route('/api', name: 'api_')]
class AnimeApiController extends AbstractController
{
    use FileManagerTrait;

    const string FILE_MANAGER_PATH = '/fileManager';

    public function __construct(
        #[Autowire('%anime.base_folder%')] private readonly string $baseFolder,
        private readonly EntityManagerInterface                    $entityManager,
        private readonly MyAnimeListService                        $malService,
        #[AutowireLocator(services: 'anime.downloader', indexAttribute: 'website')]
        private readonly ContainerInterface                        $locator)
    {
    }

    #[Route('/list/anime', name: 'list_anime', methods: ['GET'])]
    public function apiListAnime(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($this->entityManager->getRepository(ListAnime::class)->findAll());
    }

    #[Route('/list/anime/refresh', name: 'list_anime_refresh', methods: ['POST'])]
    public function apiListAnimeRefresh(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->malService->scheduleRefreshAnimeCache();
        return $this->json(['ok' => true]);
    }

    #[Route('/list/manga', name: 'list_manga', methods: ['GET'])]
    public function apiListManga(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($this->entityManager->getRepository(ListManga::class)->findAll());
    }

    #[Route('/list/manga/refresh', name: 'list_manga_refresh', methods: ['POST'])]
    public function apiListMangaRefresh(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->malService->scheduleRefreshMangaCache();
        return $this->json(['ok' => true]);
    }

    #[Route('/downloads', name: 'downloads_all', methods: ['GET'])]
    public function apiDownloadsAll(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($this->entityManager->getRepository(EpisodeDownload::class)->findAll());
    }

    /**
     * @param class-string<UnitEnum> $enumClass
     * @return array
     */
    private function filterToMap(string $enumClass): array
    {
        return array_map(
            fn($e) => ['text' => str_replace('_', ' ', ucfirst($e->value)), 'value' => $e->value],
            $enumClass::cases()
        );
    }

    #[Route('/downloads2', name: 'downloads_all2', methods: ['GET'])]
    public function apiDownloadsAll2(Request $req, EpisodeDownloadRepository $episodeRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $pagination = $req->query->all("pagination");
        $filters = $req->query->all("filters");
        $sortOrder = $req->query->getString("sortOrder");
        $sortField = $req->query->getString("sortField");
        $limit = $pagination['pageSize'];
        $page = $pagination['current'];
        $offset = $limit * ($page - 1);
        $qb = $episodeRepo->createQueryBuilder('e')->addOrderBy("e.$sortField", $sortOrder === 'descend' ? 'DESC' : 'ASC');
        $i = 1;
        foreach ($filters as $field => $values) {
            if (empty($values)) {
                continue;
            }
            $qb->andWhere("e.$field IN (:sortValues$i)")
                ->setParameter("sortValues$i", $values);
            $i++;
        }

        return $this->json([
            'filtersState' => $this->filterToMap(EpisodeDownloadState::class),
            'total' => $qb->select('COUNT(e)')->getQuery()->getSingleScalarResult(),
            'results' => $qb->select('e')->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult(),
        ]);
    }

    #[Route('/downloads', name: 'downloads_add', methods: ['POST'])]
    public function apiDownloadsAdd(Request $req, MessageBusInterface $bus): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if (!$req->request->has("url")) {
            throw new BadRequestException("Parameter url not found.");
        }
        $url = $req->request->getString("url");
        $all = $req->request->getBoolean("all", false);
        $filter = $req->request->getBoolean("filter", true);
        try {
            $urlSplits = parse_url($url);
            $baseUrl = "{$urlSplits['scheme']}://{$urlSplits['host']}";
            if(!$this->locator->has($baseUrl)) {
                throw new UnhandledWebsiteException();
            }
            /** @var AnimeDownloaderInterface $downloader */
            $downloader = $this->locator->get($baseUrl);
            $episodes = $downloader->createEpisodeDownloads($urlSplits['path'], $all, $filter);
        } catch (CacheAnimeNotFoundException $e) {
            return $this->json([]);
        }
        if (!count($episodes)) {
            return $this->json([]);
        }
        foreach ($episodes as $episode) {
            $bus->dispatch(new EpisodeDownloadNotification($episode->getId()));
        }
        return $this->json($episodes);
    }

}
