<?php

namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\EpisodeDownloadRequest;
use App\AnimeBundle\Entity\EpisodeDownloadState;
use App\AnimeBundle\Entity\ListAnimeStatus;
use App\AnimeBundle\Entity\ListAnimeType;
use App\AnimeBundle\Entity\ListMangaStatus;
use App\AnimeBundle\Entity\ListMangaType;
use App\AnimeBundle\Entity\Nsfw;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Message\EpisodeDownloadNotification;
use App\AnimeBundle\Repository\EpisodeDownloadRepository;
use App\AnimeBundle\Repository\ListAnimeRepository;
use App\AnimeBundle\Repository\ListMangaRepository;
use App\AnimeBundle\Repository\SeasonFolderRepository;
use App\AnimeBundle\Service\AnimeDownloaderLocator;
use App\AnimeBundle\Service\MyAnimeListService;
use App\CoreBundle\Entity\TableColumn;
use App\CoreBundle\Entity\TableQueryParameters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

#[IsGranted('ROLE_USER_ANIME', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiController extends AbstractController
{

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MyAnimeListService     $malService,
        private readonly AnimeDownloaderLocator $locator)
    {
    }

    #[Route('/list/anime', name: 'list_anime', methods: ['GET'])]
    public function apiListAnime(ListAnimeRepository $listRepo, #[MapQueryString] TableQueryParameters $params): Response
    {
        return $this->json([
            'columns' => [
                TableColumn::builder('ID', 'id')->setSorter(true)->setSortDirections(['descend', 'ascend']),
                TableColumn::builder('Title', 'title')->setSorter(true)->setSortDirections(['ascend', 'descend']),
                TableColumn::builder('Title En', 'title_en')->setHidden(true),
                TableColumn::builder('Nsfw', 'nsfw')->setHidden(true)->setFiltersFromEnum(Nsfw::class),
                TableColumn::builder('Type', 'media_type')->setFiltersFromEnum(ListAnimeType::class),
                TableColumn::builder('Episodes', 'num_episodes'),
                TableColumn::builder('Status', 'status')->setFiltersFromEnum(ListAnimeStatus::class),
            ],
            'count' => $params->getQueryResultCount($listRepo->createQueryBuilder('e')),
            'rows' => $params->getQueryResult($listRepo->createQueryBuilder('e')),
        ], 200, [], [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
    }

    #[Route('/season/folders', name: 'list_anime_folders', methods: ['GET'])]
    public function apiListAnimeFolders(SeasonFolderRepository $listRepo, #[MapQueryString] TableQueryParameters $params): Response
    {
        return $this->json([
            'columns' => [
                TableColumn::builder('ID', 'id')->setSorter(true)->setSortDirections(['descend', 'ascend']),
                TableColumn::builder('Folder', 'folder')->setSorter(true)->setSortDirections(['ascend', 'descend']),
            ],
            'count' => $params->getQueryResultCount($listRepo->createQueryBuilder('e')),
            'rows' => $params->getQueryResult($listRepo->createQueryBuilder('e')),
        ], 200, [], [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
    }

    #[Route('/list/manga', name: 'list_manga', methods: ['GET'])]
    public function apiListManga(ListMangaRepository $listRepo, #[MapQueryString] TableQueryParameters $params): Response
    {
        return $this->json([
            'columns' => [
                TableColumn::builder('ID', 'id')->setSorter(true)->setSortDirections(['descend', 'ascend']),
                TableColumn::builder('Title', 'title')->setSorter(true)->setSortDirections(['ascend', 'descend']),
                TableColumn::builder('Title En', 'title_en')->setHidden(true),
                TableColumn::builder('Nsfw', 'nsfw')->setHidden(true)->setFiltersFromEnum(Nsfw::class),
                TableColumn::builder('Type', 'media_type')->setFiltersFromEnum(ListMangaType::class),
                TableColumn::builder('Volumes', 'num_volumes'),
                TableColumn::builder('Chapters', 'num_chapters'),
                TableColumn::builder('Status', 'status')->setFiltersFromEnum(ListMangaStatus::class),
            ],
            'count' => $params->getQueryResultCount($listRepo->createQueryBuilder('e')),
            'rows' => $params->getQueryResult($listRepo->createQueryBuilder('e')),
        ], 200, [], [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/list/anime/refresh', name: 'list_anime_refresh', methods: ['POST'])]
    public function apiListAnimeRefresh(): Response
    {
        $this->malService->scheduleRefreshAnimeCache();
        return $this->json(['ok' => true]);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/list/manga/refresh', name: 'list_manga_refresh', methods: ['POST'])]
    public function apiListMangaRefresh(): Response
    {
        $this->malService->scheduleRefreshMangaCache();
        return $this->json(['ok' => true]);
    }

    #[Route('/downloads', name: 'downloads', methods: ['GET'])]
    public function apiDownloads(EpisodeDownloadRepository $episodeRepo, #[MapQueryString] TableQueryParameters $params): Response
    {
        return $this->json([
            'columns' => [
                TableColumn::builder('ID', 'id')->setSorter(true)->setSortDirections(['descend', 'ascend']),
                TableColumn::builder('AnimeWorld URL', 'episode_url')->setSorter(true)->setSortDirections(['ascend', 'descend']),
                TableColumn::builder('Download URL', 'download_url')->setHidden(true),
                TableColumn::builder('File', 'file')->setHidden(true),
                TableColumn::builder('Folder', 'folder'),
                TableColumn::builder('Episode', 'episode'),
                TableColumn::builder('Created', 'created')->setHidden(true),
                TableColumn::builder('Started', 'started'),
                TableColumn::builder('Completed', 'completed'),
                TableColumn::builder('State', 'state')->setFiltersFromEnum(EpisodeDownloadState::class),
                TableColumn::builder('MAL', 'mal_id'),
                TableColumn::builder('AL', 'al_id')->setHidden(true),
            ],
            'count' => $params->getQueryResultCount($episodeRepo->createQueryBuilder('e')),
            'rows' => $params->getQueryResult($episodeRepo->createQueryBuilder('e')),
        ], 200, [], [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/downloads', name: 'downloads_add', methods: ['POST'])]
    public function apiDownloadsAdd(Request $req, #[MapRequestPayload] EpisodeDownloadRequest $downloadReq, MessageBusInterface $bus): Response
    {
        try {
            $downloader = $this->locator->getService($downloadReq);
            $episodes = $downloader->createEpisodeDownloads($downloadReq);
        } catch (CacheAnimeNotFoundException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
        foreach ($episodes as $episode) {
            $bus->dispatch(new EpisodeDownloadNotification($episode->getId()));
        }
        return $this->json($episodes);
    }

}
