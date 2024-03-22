<?php

namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Message\EpisodeDownloadNotification;
use App\AnimeBundle\Service\AnimeWorldService;
use App\AnimeBundle\Service\MyAnimeListService;
use App\CoreBundle\Controller\FileManagerTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class AnimeApiController extends AbstractController
{
    use FileManagerTrait;

    const FILE_MANAGER_PATH = '/fileManager';

    public function __construct(
        #[Autowire('%anime.base_folder%')] private readonly string $baseFolder,
        private readonly EntityManagerInterface                    $entityManager,
        private readonly MyAnimeListService                        $malService,
        private readonly AnimeWorldService                         $awService)
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
            $episodes = $this->awService->createEpisodeDownloads($url, $all, $filter);
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
