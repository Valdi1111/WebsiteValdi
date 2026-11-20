<?php

namespace App\GamesBundle\Controller;

use App\GamesBundle\Service\GameProviderAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_GAMES', null, 'Access Denied.')]
class IndexController extends AbstractController
{
    #[Route('/{path}', name: 'index', requirements: ['path' => '^(?!api/).*'], methods: ['GET'], priority: -10)]
    public function index(Request $request, Discovery $discovery, GameProviderAccess $providerAccess): Response
    {
        $discovery->addLink($request);

        return $this->render('@Games/index.html.twig', [
            'providers' => $providerAccess->getGrantedProviders(),
        ]);
    }
}
