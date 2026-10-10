<?php

namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Entity\SeasonFolder;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\UnsupportedTrackerException;
use App\AnimeBundle\Exception\UnsupportedWebsiteException;
use App\AnimeBundle\Model\EpisodeDownloadRequest;
use App\AnimeBundle\Repository\EpisodeDownloadRepository;
use App\AnimeBundle\Repository\ListAnimeRepository;
use App\AnimeBundle\Repository\ListMangaRepository;
use App\AnimeBundle\Repository\SeasonFolderRepository;
use App\AnimeBundle\Service\AnimeStorage;
use App\AnimeBundle\Service\AnimeTrackerLocator;
use App\AnimeBundle\Service\EpisodeDownloadManager;
use App\CoreBundle\Model\StandardTable\TableConfiguration;
use App\CoreBundle\Model\StandardTable\TableJoin;
use App\CoreBundle\Model\StandardTable\TableParameters;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_ANIME', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiController extends AbstractController
{

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeStorage           $animeStorage,
    )
    {
    }

    public function getFilesystem(): Filesystem
    {
        return $this->animeStorage;
    }

    #[Route('/{tracker}/season-folders/table', name: 'tracker_season_folders_table', methods: ['GET'])]
    public function apiSeasonFoldersTable(
        string                 $tracker,
        SeasonFolderRepository $foldersRepo,
        ListAnimeRepository    $listRepo,
        #[MapQueryString]
        TableParameters        $params
    ): Response
    {
        $config = new TableConfiguration(
            rootEntityClass: SeasonFolder::class,
            rootAlias: 'e',
            fieldMappings: [
                'id' => 'e.id',
                'tracker' => 'e.tracker',
                'folder' => 'e.folder',
                'episode_offset' => 'e.episodeOffset',
                'title' => 'a.title',
            ],
            joins: [
                TableJoin::left(
                    join: ListAnime::class,
                    alias: 'a',
                    condition: 'a.id = e.id AND a.tracker = e.tracker'
                ),
            ],
            queryModifier: fn($qb, $alias) => $qb
                ->andWhere("$alias.tracker = :fixedTracker")
                ->setParameter('fixedTracker', $tracker),
        );

        return $this->json([
            'rows' => $foldersRepo->getTableRows($params, $config),
            'count' => $foldersRepo->getTableCount($params, $config),
            'total_count' => $foldersRepo->getTableUnfilteredCount($config),
        ]);
    }

    #[Route('/{tracker}/season-folders/{id}', name: 'tracker_season_folders_id', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiSeasonFoldersId(
        #[MapEntity(mapping: ['id' => 'id', 'tracker' => 'tracker'], message: "Season not found.")]
        SeasonFolder $season
    ): Response
    {
        return $this->json($season);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/{tracker}/season-folders', name: 'tracker_season_folders_add', methods: ['POST'])]
    public function apiSeasonFoldersAdd(
        string                    $tracker,
        #[MapRequestPayload]
        SeasonFolder              $season,
        SeasonFolderRepository    $seasonRepo,
        EpisodeDownloadRepository $downloadRepo
    ): Response
    {
        $season->setTracker($tracker);
        // Check uniqueness across both ID and tracker
        if ($seasonRepo->findOneBy(['id' => $season->getId(), 'tracker' => $season->getTracker()])) {
            throw new ConflictHttpException('Season folder already exists for this tracker.');
        }
        if (!$this->getFilesystem()->directoryExists($season->getFolder())) {
            throw new ConflictHttpException('Folder not found!');
        }
        $this->entityManager->persist($season);

        // Move existing downloaded files for this series according to its tracker
        $downloads = $downloadRepo->findBy([
            'tracker' => $season->getTracker(),
            'trackerId' => $season->getId(),
        ]);
        foreach ($downloads as $download) {
            if ($download->getFolder() !== $season->getFolder()) {
                $oldEpisodePath = Path::join($download->getFolder(), $download->getFile());
                $download->setFolder($season->getFolder());
                if ($this->getFilesystem()->fileExists($oldEpisodePath)) {
                    $newEpisodePath = Path::join($download->getFolder(), $download->getFile());
                    $this->getFilesystem()->move($oldEpisodePath, $newEpisodePath);
                }
            }
        }

        $this->entityManager->flush();
        return $this->json($season);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/{tracker}/season-folders/{id}', name: 'tracker_season_folders_id_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function apiSeasonFoldersDelete(
        #[MapEntity(mapping: ['id' => 'id', 'tracker' => 'tracker'], message: "Season not found.")]
        SeasonFolder $season
    ): Response
    {
        $this->entityManager->remove($season);
        $this->entityManager->flush();
        return $this->json(['id' => $season->getId(), 'tracker' => $season->getTracker()]);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/{tracker}/season-folders/{id}/downloads', name: 'tracker_season_folders_id_downloads', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiSeasonFoldersIdDownloads(
        string $tracker,
        int $id,
        EpisodeDownloadRepository $downloadRepo
    ): Response
    {
        $downloads = array_map(
            fn(EpisodeDownload $download) => [
                "file_exists" => $this->getFilesystem()->fileExists(Path::join($download->getFolder(), $download->getFile())),
                "download" => $download,
            ],
            $downloadRepo->findBy([
                'tracker' => $tracker,
                'trackerId' => $id,
            ]),
        );
        return $this->json($downloads);
    }

    #[Route('/{tracker}/list-anime/table', name: 'tracker_list_anime_table', methods: ['GET'])]
    public function apiListAnimeTable(
        string              $tracker,
        ListAnimeRepository $listRepo,
        #[MapQueryString]
        TableParameters     $params
    ): Response
    {
        $config = new TableConfiguration(
            rootEntityClass: ListAnime::class,
            rootAlias: 'e',
            fieldMappings: [
                'id' => 'e.id',
                'tracker' => 'e.tracker',
                'title' => 'e.title',
                'title_en' => 'e.titleEn',
                'num_episodes' => 'e.numEpisodes',
                'status' => 'e.status',
                'media_type' => 'e.mediaType',
                'nsfw' => 'e.nsfw',
            ],
            queryModifier: fn($qb, $alias) => $qb
                ->andWhere("$alias.tracker = :fixedTracker")
                ->setParameter('fixedTracker', $tracker),
        );

        return $this->json([
            'rows' => $listRepo->getTableRows($params, $config),
            'count' => $listRepo->getTableCount($params, $config),
            'total_count' => $listRepo->getTableUnfilteredCount($config),
        ]);
    }

    #[Route('/{tracker}/list-anime/{id}', name: 'tracker_list_anime_id', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiListAnimeId(
        #[MapEntity(mapping: ['id' => 'id', 'tracker' => 'tracker'], message: "Anime not found.")]
        ListAnime $anime
    ): Response
    {
        return $this->json($anime);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/{tracker}/list-anime/refresh', name: 'tracker_list_anime_refresh', methods: ['POST'])]
    public function apiListAnimeRefresh(
        string              $tracker,
        MessageBusInterface $bus
    ): Response
    {
        $bus->dispatch(new RedispatchMessage(new RunCommandMessage("anime:cache-refresh anime --tracker $tracker"), 'core_async'));
        return $this->json(['ok' => true]);
    }

    #[Route('/{tracker}/list-manga/table', name: 'tracker_list_manga_table', methods: ['GET'])]
    public function apiListMangaTable(
        string              $tracker,
        ListMangaRepository $listRepo,
        #[MapQueryString]
        TableParameters     $params
    ): Response
    {
        $config = new TableConfiguration(
            rootEntityClass: ListManga::class,
            rootAlias: 'e',
            fieldMappings: [
                'id' => 'e.id',
                'tracker' => 'e.tracker',
                'title' => 'e.title',
                'title_en' => 'e.titleEn',
                'num_volumes' => 'e.numVolumes',
                'num_chapters' => 'e.numChapters',
                'status' => 'e.status',
                'media_type' => 'e.mediaType',
                'nsfw' => 'e.nsfw',
            ],
            queryModifier: fn($qb, $alias) => $qb
                ->andWhere("$alias.tracker = :fixedTracker")
                ->setParameter('fixedTracker', $tracker),
        );

        return $this->json([
            'rows' => $listRepo->getTableRows($params, $config),
            'count' => $listRepo->getTableCount($params, $config),
            'total_count' => $listRepo->getTableUnfilteredCount($config),
        ]);
    }

    #[Route('/{tracker}/list-manga/{id}', name: 'tracker_list_manga_id', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiListMangaId(
        #[MapEntity(mapping: ['id' => 'id', 'tracker' => 'tracker'], message: "Manga not found.")]
        ListManga $manga
    ): Response
    {
        return $this->json($manga);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/{tracker}/list-manga/refresh', name: 'tracker_list_manga_refresh', methods: ['POST'])]
    public function apiListMangaRefresh(
        string              $tracker,
        MessageBusInterface $bus
    ): Response
    {
        $bus->dispatch(new RedispatchMessage(new RunCommandMessage("anime:cache-refresh manga --tracker $tracker"), 'core_async'));
        return $this->json(['ok' => true]);
    }

    #[Route('/downloads/table', name: 'downloads_table', methods: ['GET'])]
    public function apiDownloadsTable(
        EpisodeDownloadRepository $episodeRepo,
        #[MapQueryString]
        TableParameters           $params
    ): Response
    {
        $config = new TableConfiguration(
            rootEntityClass: EpisodeDownload::class,
            rootAlias: 'e',
            fieldMappings: [
                'id' => 'e.id',
                'provider' => 'e.provider',
                'tracker' => 'e.tracker',
                'tracker_id' => 'e.trackerId',
                'episode_url' => 'e.episodeUrl',
                'folder' => 'e.folder',
                'file' => 'e.file',
                'episode' => 'e.episode',
                'started' => 'e.started',
                'completed' => 'e.completed',
                'state' => 'e.state',
                'mal_id' => 'e.malId',
                'al_id' => 'e.alId',
            ],
            joins: [
                'a' => 'e.episodeDownloadAttempts',
            ],
            fetchJoins: ['a'],
            hydrateObjects: true,
            rowTransformer: function (array $row, EpisodeDownload $entity): array {
                // Include last attempt details for easy error inspection in UI tables
                $lastAttempt = $entity->getLastAttempt();
                $row['last_error'] = $lastAttempt?->getErrorMessage();
                $row['attempts_count'] = $entity->getEpisodeDownloadAttempts()->count();
                return $row;
            }
        );

        return $this->json([
            'rows' => $episodeRepo->getTableRows($params, $config),
            'count' => $episodeRepo->getTableCount($params, $config),
            'total_count' => $episodeRepo->getTableUnfilteredCount($config),
        ]);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/downloads', name: 'downloads_add', methods: ['POST'])]
    public function apiDownloadsAdd(
        #[MapRequestPayload]
        EpisodeDownloadRequest $downloadReq,
        EpisodeDownloadManager $downloadManager
    ): Response
    {
        try {
            $downloads = $downloadManager->processDownloadRequest($downloadReq);
        } catch (UnsupportedWebsiteException $e) {
            throw new BadRequestHttpException("No service has been found for the given url.", $e);
        } catch (CacheAnimeNotFoundException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return $this->json($downloads);
    }

    #[Route('/downloads/{download}', name: 'downloads_id', requirements: ['download' => '\d+'], methods: ['GET'])]
    public function apiDownloadsId(#[MapEntity(message: "Download not found.")] EpisodeDownload $download): Response
    {
        return $this->json([
            "download" => $download,
            "attempts" => $download->getEpisodeDownloadAttempts()->toArray(),
        ]);
    }

    #[Route('/{tracker}/anime-title/{id}', name: 'tracker_anime_title', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiAnimeTitle(
        string           $tracker,
        int              $id,
        AnimeTrackerLocator $trackerLocator
    ): Response {
        try {
            $t = $trackerLocator->get($tracker);
        } catch (UnsupportedTrackerException $e) {
            throw $this->createNotFoundException("Tracker '$tracker' not found.");
        }

        return $this->json($t->fetchAnimeTitle($id));
    }

    #[Route('/{tracker}/manga-title/{id}', name: 'tracker_manga_title', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiMangaTitle(
        string           $tracker,
        int              $id,
        AnimeTrackerLocator $trackerLocator
    ): Response {
        try {
            $t = $trackerLocator->get($tracker);
        } catch (UnsupportedTrackerException $e) {
            throw $this->createNotFoundException("Tracker '$tracker' not found.");
        }

        return $this->json($t->fetchMangaTitle($id));
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/downloads/{download}/retry', name: 'downloads_id_retry', methods: ['POST'])]
    public function apiDownloadsIdRetry(
        #[MapEntity(message: "Download not found.")] EpisodeDownload $download,
        EpisodeDownloadManager                                       $downloadManager
    ): Response
    {
        try {
            $downloadManager->retryDownload($download);
        } catch (UnsupportedWebsiteException $e) {
            throw new BadRequestHttpException("No provider has been found for the given download.");
        }

        return $this->json($download);
    }

}
