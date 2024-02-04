<?php

namespace App\AnimeBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

class AnimeIndexController extends AbstractController
{

    #[Route('/{path}', name: 'index', requirements: ['path' => '.*'], methods: ['GET'], priority: -10)]
    public function index(Request $request, HubInterface $hub, Discovery $discovery, Authorization $authorization): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $authorization->setCookie($request, ['https://books.valdi.ovh/books/updates']);
        $discovery->addLink($request);
        return $this->render('@AnimeBundle/index.html.twig', [
            'mercure_hub_url' => $hub->getPublicUrl(),
        ]);
    }

}
