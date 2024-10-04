<?php

namespace App\VideosBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_VIDEOS')]
class VideosIndexController extends AbstractController
{

    #[Route('/{path}', name: 'index', requirements: ['path' => '^(?!api/).*'], methods: ['GET'], priority: -10)]
    public function index(Request $request, HubInterface $hub, Discovery $discovery): Response
    {
        $discovery->addLink($request);
        return $this->render('@Videos/index.html.twig', [
            'mercure_hub_url' => $hub->getPublicUrl(),
        ]);
    }

}
