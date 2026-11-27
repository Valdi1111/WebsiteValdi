<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Attribute\MapDiscordInteraction;
use App\CoreBundle\Model\Discord\Interaction\DiscordInteraction;
use App\CoreBundle\Service\Chat\Discord\DiscordInteractionHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Interactions Endpoint URL of the Discord application: slash commands, buttons and autocomplete of the chat bot.
 * Discord signs every request (#[MapDiscordInteraction] verifies it) and waits 3 seconds at most for the answer.
 *
 * On the firewall discord_interactions (security: false): no session, no remember-me. Without host, like every
 * CoreBundle route, so the URL to register is on DEFAULT_URI.
 */
class DiscordInteractionsController extends AbstractController
{
    public function __construct(
        private readonly DiscordInteractionHandler $handler,
    ) {
    }

    #[Route('/discord/interactions', name: 'discord_interactions', methods: ['POST'])]
    public function __invoke(#[MapDiscordInteraction] DiscordInteraction $interaction): JsonResponse
    {
        return new JsonResponse($this->handler->handle($interaction));
    }
}
