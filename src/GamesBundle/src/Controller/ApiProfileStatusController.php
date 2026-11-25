<?php

namespace App\GamesBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Presenter\ProfileStatusViewFactory;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\GamesBundle\Service\Status\ProfileStatusRefreshLimiter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Live status of one game profile, for the profile card of the games page, read only when the user asks for it.
 *
 * The path sits under the API prefix of each provider bundle (/api/hoyoverse...), so the provider client of the page
 * calls it as its own endpoints, but one controller serves every provider: the profile is looked up through
 * GameProfileRegistry, with the role and owner checks of the bot commands. A profile of another user, or of a provider
 * the user has no role for, is not found.
 */
#[IsGranted('ROLE_USER_GAMES', null, 'Access Denied.')]
class ApiProfileStatusController extends AbstractController
{
    #[Route(
        '/api/{provider}/gameProfiles/{gameProfile}/status',
        name: 'api_profile_status',
        requirements: ['provider' => '[a-z]+', 'gameProfile' => '\d+'],
        methods: ['GET'],
        format: 'json',
    )]
    public function status(
        string $provider,
        int $gameProfile,
        #[CurrentUser] User $user,
        GameProfileRegistry $registry,
        GameProfileStatusCollector $collector,
        ProfileStatusViewFactory $viewFactory,
        ProfileStatusRefreshLimiter $refreshLimiter,
        // ?fresh=1 skips the caches (notes, check-in, endgames) and reads the game APIs again
        #[MapQueryParameter] bool $fresh = false,
    ): JsonResponse {
        $ref = new GameProfileRef($provider, $gameProfile);
        $profile = $registry->resolve($user, $ref) ?? throw $this->createNotFoundException('Game profile not found.');

        // Counted only for a profile of the user, so another user's reference never shows a 429
        if ($fresh) {
            $refreshLimiter->consume($user, $ref);
        }

        [$status] = $collector->collect([$profile], null, $fresh);

        return $this->json($viewFactory->create($status));
    }
}
