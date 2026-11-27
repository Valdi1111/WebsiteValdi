<?php

namespace App\CoreBundle\Model\Discord\Interaction;

/**
 * The "data" of an interaction: the command and its options (application command, autocomplete), or the pressed
 * component (message component).
 */
final readonly class DiscordInteractionData
{
    /**
     * @param string|null                    $name          command name
     * @param int|null                       $type          command type (1 = slash command)
     * @param list<DiscordInteractionOption> $options       options given, a sub-command holding its own
     * @param string|null                    $customId      the custom_id of the pressed button (ChatActionCodec)
     * @param int|null                       $componentType 2 = button
     */
    public function __construct(
        public ?string $name = null,
        public ?int $type = null,
        public array $options = [],
        public ?string $customId = null,
        public ?int $componentType = null,
    ) {
    }
}
