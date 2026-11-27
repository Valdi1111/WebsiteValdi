<?php

namespace App\CoreBundle\Model\Discord\Interaction;

/**
 * The server member who sent an interaction from a server.
 */
final readonly class DiscordInteractionMember
{
    public function __construct(
        public ?DiscordInteractionUser $user = null,
    ) {
    }
}
