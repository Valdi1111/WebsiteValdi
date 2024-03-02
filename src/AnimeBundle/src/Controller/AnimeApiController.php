<?php

namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Entity\ListManga;
use App\AnimeBundle\Service\AnimeWorldService;
use App\AnimeBundle\Service\MyAnimeListService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class AnimeApiController extends AbstractController
{

    public function __construct(private readonly EntityManagerInterface $animeEntityManager, private readonly MyAnimeListService $malService, private readonly AnimeWorldService $awService)
    {
    }

    #[Route('/list/anime', name: 'list_anime', methods: ['GET'])]
    public function apiListAnime(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($this->animeEntityManager->getRepository(ListAnime::class)->findAll());
    }

    #[Route('/list/anime/refresh', name: 'list_anime_refresh', methods: ['POST'])]
    public function apiListAnimeRefresh(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($this->malService->refreshAnimeCache());
    }

    #[Route('/list/manga', name: 'list_manga', methods: ['GET'])]
    public function apiListManga(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($this->animeEntityManager->getRepository(ListManga::class)->findAll());
    }

    #[Route('/list/manga/refresh', name: 'list_manga_refresh', methods: ['POST'])]
    public function apiListMangaRefresh(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($this->malService->refreshMangaCache());
    }

    #[Route('/downloads', name: 'downloads_all', methods: ['GET'])]
    public function apiDownloadsAll(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($this->animeEntityManager->getRepository(EpisodeDownload::class)->findAll());
    }

    #[Route('/downloads', name: 'downloads_add', methods: ['POST'])]
    public function apiDownloadsAdd(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if(!$req->request->has("url")) {
            throw new BadRequestException("Parameter url not found.");
        }
        $url = $req->request->getString("url");
        $all = $req->request->getBoolean("all", false);
        $filter = $req->request->getBoolean("filter", true);
        return $this->json($this->awService->createEpisodeDownloads($url, $all, $filter));
    }

}
