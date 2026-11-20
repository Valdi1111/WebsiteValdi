<?php

namespace App\GamesBundle\Controller;

use App\GamesBundle\Service\GeetestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves the official GeeTest loader scripts (proxied and cached) used by the provider login frames.
 */
#[IsGranted('ROLE_USER_GAMES', null, 'Access Denied.')]
class GeetestController extends AbstractController
{
    public function __construct(
        private readonly GeetestService $geetestService,
    ) {
    }

    #[Route('/api/geetest-script/{version}', name: 'geetest_script', requirements: ['version' => 'v3|v4'], methods: ['GET'], format: 'js')]
    public function geetestScript(string $version): Response
    {
        $content = $this->geetestService->fetchScript($version);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
