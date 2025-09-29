<?php

namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeDownloadRequest;
use App\AnimeBundle\Entity\EpisodeDownloadState;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListAnimeStatus;
use App\AnimeBundle\Entity\ListAnimeType;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Entity\ListMangaStatus;
use App\AnimeBundle\Entity\ListMangaType;
use App\AnimeBundle\Entity\Nsfw;
use App\AnimeBundle\Entity\SeasonFolder;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\UnhandledWebsiteException;
use App\AnimeBundle\Message\EpisodeDownloadNotification;
use App\AnimeBundle\Repository\EpisodeDownloadRepository;
use App\AnimeBundle\Repository\ListAnimeRepository;
use App\AnimeBundle\Repository\ListMangaRepository;
use App\AnimeBundle\Repository\SeasonFolderRepository;
use App\AnimeBundle\Service\AnimeDownloaderLocator;
use App\AnimeBundle\Service\MyAnimeListService;
use App\CoreBundle\Entity\Table;
use App\CoreBundle\Entity\TableParameters;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

#[IsGranted('ROLE_USER_ANIME', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiController extends AbstractController
{

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%anime.base_folder%')]
        private readonly string                 $baseFolder)
    {
    }

    #[Route('/season-folders', name: 'season_folders', methods: ['GET'])]
    public function apiSeasonFolders(SeasonFolderRepository $listRepo, #[MapQueryString] TableParameters $params): Response
    {
        $table = new Table($listRepo, $params);
        $table->getDefaultParameters()
            ->setSorterField('id')
            ->setSorterOrder('descend');
        $table->addColumn('ID', 'id')
            ->setFixedLeft()->setSorter(true)
            ->setSortDirections(['descend', 'ascend'])
            ->setDefaultSortOrder('descend');
        $table->addColumn('Folder', 'folder')
            ->setSorter(true)
            ->setSortDirections(['ascend', 'descend']);
        return $this->json($table);
    }

    #[Route('/season-folders/available', name: 'season_folders_available', methods: ['GET'])]
    public function apiSeasonFoldersAvailable(#[MapQueryParameter] ?string $path = "/"): Response
    {
        $adapter = new LocalFilesystemAdapter($this->baseFolder);
        $filesystem = new Filesystem($adapter);
        $contents = $filesystem->listContents($path)
            ->filter(fn(StorageAttributes $attributes) => $attributes->isDir())
            ->sortByPath()
            ->map(function (DirectoryAttributes $attributes) use ($path, $filesystem) {
                $children = $filesystem->listContents($attributes->path())
                    ->filter(fn(StorageAttributes $attributes) => $attributes->isDir())
                    ->toArray();
                $title = $attributes->path();
                if ($path !== "/") {
                    $title = substr($title, strlen($path));
                }
                return [
                    "id" => "/" . $attributes->path(),
                    "pId" => $path ?: 0,
                    "value" => "/" . $attributes->path(),
                    "title" => $title,
                    "isLeaf" => empty($children),
                ];
            })
            ->toArray();
        return $this->json($contents);
    }

    #[Route('/season-folders/{season}', name: 'season_folders_id', requirements: ['season' => '\d+'], methods: ['GET'])]
    public function apiSeasonFoldersId(#[MapEntity(message: "Season not found.")] SeasonFolder $season): Response
    {
        return $this->json($season);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/season-folders', name: 'season_folders_add', methods: ['POST'])]
    public function apiSeasonFoldersAdd(#[MapRequestPayload] SeasonFolder $season, SeasonFolderRepository $seasonRepo): Response
    {
        if ($seasonRepo->find($season->getId())) {
            throw new ConflictHttpException('Season already exists.');
        }
        $this->entityManager->persist($season);
        $this->entityManager->flush();
        return $this->json($season);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/season-folders/{season}', name: 'season_folders_id_delete', requirements: ['season' => '\d+'], methods: ['DELETE'])]
    public function apiSeasonFoldersDelete(#[MapEntity(message: "Season not found.")]  SeasonFolder $season): Response
    {
        $id = $season->getId();
        $this->entityManager->remove($season);
        $this->entityManager->flush();
        return $this->json(['id' => $id]);
    }

    #[Route('/list-anime', name: 'list_anime', methods: ['GET'])]
    public function apiListAnime(ListAnimeRepository $listRepo, #[MapQueryString] TableParameters $params): Response
    {
        $table = new Table($listRepo, $params);
        $table->getDefaultParameters()
            ->setSorterField('id')
            ->setSorterOrder('descend');
        $table->addColumn('ID', 'id')
            ->setFixedLeft()
            ->setSorter(true)
            ->setSortDirections(['descend', 'ascend'])
            ->setDefaultSortOrder('descend');
        $table->addColumn('Title', 'title')
            ->setSorter(true)
            ->setSortDirections(['ascend', 'descend']);
        $table->addColumn('Title English', 'title_en')
            ->setHidden(true);
        $table->addColumn('Nsfw', 'nsfw')
            ->setHidden(true)
            ->setFiltersFromEnum(Nsfw::class);
        $table->addColumn('Type', 'media_type')
            ->setFiltersFromEnum(ListAnimeType::class);
        $table->addColumn('Episodes', 'num_episodes');
        $table->addColumn('Status', 'status')
            ->setFiltersFromEnum(ListAnimeStatus::class);
        return $this->json($table);
    }

    #[Route('/list-anime/{anime}', name: 'list_anime_id', requirements: ['anime' => '\d+'], methods: ['GET'])]
    public function apiListAnimeId(#[MapEntity(message: "Anime not found.")] ListAnime $anime): Response
    {
        return $this->json($anime);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/list-anime/refresh', name: 'list_anime_refresh', methods: ['POST'])]
    public function apiListAnimeRefresh(MyAnimeListService $malService): Response
    {
        $malService->scheduleRefreshAnimeCache();
        return $this->json(['ok' => true]);
    }

    #[Route('/list-manga', name: 'list_manga', methods: ['GET'])]
    public function apiListManga(ListMangaRepository $listRepo, #[MapQueryString] TableParameters $params): Response
    {
        $table = new Table($listRepo, $params);
        $table->getDefaultParameters()
            ->setSorterField('id')
            ->setSorterOrder('descend');
        $table->addColumn('ID', 'id')
            ->setFixedLeft()
            ->setSorter(true)
            ->setSortDirections(['descend', 'ascend'])
            ->setDefaultSortOrder('descend');
        $table->addColumn('Title', 'title')
            ->setSorter(true)
            ->setSortDirections(['ascend', 'descend']);
        $table->addColumn('Title English', 'title_en')
            ->setHidden(true);
        $table->addColumn('Nsfw', 'nsfw')
            ->setHidden(true)
            ->setFiltersFromEnum(Nsfw::class);
        $table->addColumn('Type', 'media_type')
            ->setFiltersFromEnum(ListMangaType::class);
        $table->addColumn('Volumes', 'num_volumes');
        $table->addColumn('Chapters', 'num_chapters');
        $table->addColumn('Status', 'status')
            ->setFiltersFromEnum(ListMangaStatus::class);
        return $this->json($table);
    }

    #[Route('/list-manga/{manga}', name: 'list_manga_id', requirements: ['manga' => '\d+'], methods: ['GET'])]
    public function apiListMangaId(#[MapEntity(message: "Manga not found.")] ListManga $manga): Response
    {
        return $this->json($manga);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/list-manga/refresh', name: 'list_manga_refresh', methods: ['POST'])]
    public function apiListMangaRefresh(MyAnimeListService $malService): Response
    {
        $malService->scheduleRefreshMangaCache();
        return $this->json(['ok' => true]);
    }

    #[Route('/downloads', name: 'downloads', methods: ['GET'])]
    public function apiDownloads(EpisodeDownloadRepository $episodeRepo, #[MapQueryString] TableParameters $params): Response
    {
        $table = new Table($episodeRepo, $params);
        $table->getDefaultParameters()
            ->setSorterField('id')
            ->setSorterOrder('descend');
        $table->addColumn('ID', 'id')
            ->setFixedLeft()
            ->setSorter(true)
            ->setSortDirections(['descend', 'ascend'])
            ->setDefaultSortOrder('descend');
        $table->addColumn('Episode URL', 'episode_url')
            ->setFilterTypeString()
            ->setSorter(true)
            ->setSortDirections(['ascend', 'descend']);
        $table->addColumn('Download URL', 'download_url')
            ->setHidden(true);
        $table->addColumn('File', 'file')
            ->setHidden(true);
        $table->addColumn('Folder', 'folder');
        $table->addColumn('Episode', 'episode');
        $table->addColumn('Created', 'created')
            ->setValueFormat("datetime")
            ->setHidden(true);
        $table->addColumn('Started', 'started')
            ->setValueFormat("datetime");
        $table->addColumn('Completed', 'completed')
            ->setValueFormat("datetime");
        $table->addColumn('State', 'state')
            ->setFiltersFromEnum(EpisodeDownloadState::class);
        $table->addColumn('MAL', 'mal_id');
        $table->addColumn('AL', 'al_id')
            ->setHidden(true);
        return $this->json($table);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/downloads', name: 'downloads_add', methods: ['POST'])]
    public function apiDownloadsAdd(#[MapRequestPayload] EpisodeDownloadRequest $downloadReq, AnimeDownloaderLocator $locator, MessageBusInterface $bus): Response
    {
        try {
            $downloader = $locator->getService($downloadReq);
            $downloads = $downloader->createEpisodeDownloads($downloadReq);
        } catch (UnhandledWebsiteException $e) {
            throw new BadRequestHttpException("No service has been found for the given url.", $e);
        } catch (CacheAnimeNotFoundException $e) {
            throw new BadRequestHttpException("This series isn't on your anime list", $e);
        }
        foreach ($downloads as $download) {
            // TODO parametrizzare o rendere opzionale
            $bus->dispatch(new EpisodeDownloadNotification($download->getId()), [new DelayStamp(60000)]);
        }
        return $this->json($downloads);
    }

    #[Route('/downloads/{download}', name: 'downloads_id', requirements: ['download' => '\d+'], methods: ['GET'])]
    public function apiDownloadsId(#[MapEntity(message: "Download not found.")] EpisodeDownload $download): Response
    {
        return $this->json($download);
    }

    #[IsGranted('ROLE_ADMIN_ANIME', null, 'Access Denied.')]
    #[Route('/downloads/{download}/retry', name: 'downloads_id_retry', methods: ['POST'])]
    public function apiDownloadsIdRetry(#[MapEntity(message: "Download not found.")] EpisodeDownload $download, MessageBusInterface $bus): Response
    {
        $adapter = new LocalFilesystemAdapter($this->baseFolder);
        $filesystem = new Filesystem($adapter);
        $file = Path::join($download->getFolder(), $download->getFile());
        if ($filesystem->fileExists($file)) {
            $filesystem->delete($file);
        }
        $bus->dispatch(new EpisodeDownloadNotification($download->getId()));
        return $this->json($download);
    }

}
