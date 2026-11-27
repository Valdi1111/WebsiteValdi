<?php

namespace App\CoreBundle\Model\Discord\Interaction;

/**
 * The Discord user who sent an interaction: the id is what User::$discordUserId holds once linked.
 */
final readonly class DiscordInteractionUser
{
    /**
     * @param string $id snowflake, kept as the string the API gives
     */
    public function __construct(
        public string $id,
    ) {
    }
}
