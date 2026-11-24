<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Profile\DiscordAccountView;
use App\CoreBundle\Service\Discord\DiscordAccountLinker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Discord account of the logged-in user, for the "Connected accounts" card of the profile page.
 * Linking is a full-page OAuth2 flow: DiscordLinkController.
 */
#[IsGranted('ROLE_USER', null, 'Access Denied.')]
#[Route('/api/me/discord', name: 'api_me_discord_', format: 'json')]
class ApiMeDiscordController extends AbstractController
{
    public function __construct(private readonly DiscordAccountLinker $linker)
    {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function apiMeDiscordGet(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(DiscordAccountView::fromUser($user));
    }

    /**
     * Unlinks the account; also when none is linked. The notifications and the bot stop reaching the user on Discord.
     */
    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function apiMeDiscordDelete(#[CurrentUser] User $user): Response
    {
        $this->linker->unlink($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
