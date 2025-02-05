<?php

namespace App\AnimeBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_ANIME', null, 'Access Denied.')]
class IndexController extends AbstractController
{

    #[Route('/{path}', name: 'index', requirements: ['path' => '^(?!api/).*'], methods: ['GET'], priority: -10)]
    public function index(Request $request, Discovery $discovery): Response
    {
        $discovery->addLink($request);
        return $this->render('@Anime/index.html.twig');
    }

}
