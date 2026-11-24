<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Profile\TelegramAccountView;
use App\CoreBundle\Service\Telegram\TelegramAccountLinker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Telegram account of the logged-in user, for the "Connected accounts" card of the profile page.
 * Linking is a full-page OpenID Connect flow: TelegramLinkController.
 */
#[IsGranted('ROLE_USER', null, 'Access Denied.')]
#[Route('/api/me/telegram', name: 'api_me_telegram_', format: 'json')]
class ApiMeTelegramController extends AbstractController
{
    public function __construct(private readonly TelegramAccountLinker $linker)
    {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function apiMeTelegramGet(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(TelegramAccountView::fromUser($user));
    }

    /**
     * Unlinks the account; also when none is linked. The notifications stop reaching the user on Telegram.
     */
    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function apiMeTelegramDelete(#[CurrentUser] User $user): Response
    {
        $this->linker->unlink($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
