<?php

namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\EpisodeDownloadState;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\UnhandledWebsiteException;
use App\AnimeBundle\Message\EpisodeDownloadNotification;
use App\AnimeBundle\Repository\EpisodeDownloadRepository;
use App\AnimeBundle\Repository\ListAnimeRepository;
use App\AnimeBundle\Repository\ListMangaRepository;
use App\AnimeBundle\Service\AnimeDownloaderInterface;
use App\AnimeBundle\Service\MyAnimeListService;
use App\CoreBundle\Controller\FileManagerTrait;
use App\CoreBundle\Entity\TableColumn;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
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

    #[Route('/list/anime', name: 'list_anime', methods: ['GET'], format: 'json')]
    public function apiListAnime(ListAnimeRepository $listRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($listRepo->findAll());
    }

    #[Route('/list/manga', name: 'list_manga', methods: ['GET'], format: 'json')]
    public function apiListManga(ListMangaRepository $listRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($listRepo->findAll());
    }

    #[Route('/list/anime/refresh', name: 'list_anime_refresh', methods: ['POST'], format: 'json')]
    public function apiListAnimeRefresh(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->malService->scheduleRefreshAnimeCache();
        return $this->json(['ok' => true]);
    }

    #[Route('/list/manga/refresh', name: 'list_manga_refresh', methods: ['POST'], format: 'json')]
    public function apiListMangaRefresh(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->malService->scheduleRefreshMangaCache();
        return $this->json(['ok' => true]);
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

    #[Route('/downloads', name: 'downloads', methods: ['GET'], format: 'json')]
    public function apiDownloads(Request $req, EpisodeDownloadRepository $episodeRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $pagination = $req->query->all("pagination");
        $filters = $req->query->all("filters");
        $sortOrder = $req->query->getString("sortOrder");
        $sortField = $req->query->getString("sortField");
        $limit = $pagination['pageSize'];
        $page = $pagination['current'];
        $offset = $limit * ($page - 1);
        $qb = $episodeRepo->createQueryBuilder('e');
        if ($sortField) {
            $field = lcfirst(str_replace('_', '', ucwords($sortField, '_')));
            $qb->addOrderBy("e.$field", $sortOrder === 'descend' ? 'DESC' : 'ASC');
        }
        foreach ($filters as $filterField => $values) {
            $field = lcfirst(str_replace('_', '', ucwords($filterField, '_')));
            if (empty($values)) {
                continue;
            }
            $qb->andWhere("e.$field IN (:{$field}Values)")
                ->setParameter("{$field}Values", $values);
        }

        return $this->json([
            'columns' => [
                TableColumn::builder('ID', 'id')->setSorter(true)->setSortDirections(['ascend', 'descend', 'ascend'])->setDefaultSortOrder('descend'),
                TableColumn::builder('AnimeWorld URL', 'episode_url')->setSorter(true)->setSortDirections(['ascend', 'descend', 'ascend']),
                TableColumn::builder('Download URL', 'download_url')->setHidden(true),
                TableColumn::builder('File', 'file')->setHidden(true),
                TableColumn::builder('Folder', 'folder'),
                TableColumn::builder('Episode','episode'),
                TableColumn::builder('Created','created')->setHidden(true),
                TableColumn::builder('Started', 'started'),
                TableColumn::builder('Completed','completed'),
                TableColumn::builder('State','state')->setFiltersFromEnum(EpisodeDownloadState::class),
                TableColumn::builder('MAL','mal_id'),
                TableColumn::builder('AL','al_id')->setHidden(true),
            ],
            'count' => $qb->select('COUNT(e)')
                ->getQuery()
                ->getSingleScalarResult(),
            'rows' => $qb->select('e')
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        ]);
    }

    #[Route('/downloads', name: 'downloads_add', methods: ['POST'], format: 'json')]
    public function apiDownloadsAdd(Request $req, MessageBusInterface $bus): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if (!$req->getPayload()->has("url")) {
            throw new BadRequestHttpException("Parameter 'url' not found.");
        }
        $url = $req->getPayload()->getString("url");
        $all = $req->getPayload()->getBoolean("all", false);
        $filter = $req->getPayload()->getBoolean("filter", true);
        try {
            $urlSplits = parse_url($url);
            $baseUrl = "{$urlSplits['scheme']}://{$urlSplits['host']}";
            if (!$this->locator->has($baseUrl)) {
                throw new UnhandledWebsiteException();
            }
            /** @var AnimeDownloaderInterface $downloader */
            $downloader = $this->locator->get($baseUrl);
            $episodes = $downloader->createEpisodeDownloads($urlSplits['path'], $all, $filter);
        } catch (CacheAnimeNotFoundException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
        foreach ($episodes as $episode) {
            $bus->dispatch(new EpisodeDownloadNotification($episode->getId()));
        }
        return $this->json($episodes);
    }

}
