<?php

namespace App\CoreBundle\Model\Discord\Interaction;

/**
 * An option given to a command: a value, or a sub-command (group) holding its own options.
 */
final readonly class DiscordInteractionOption
{
    public const int TYPE_SUB_COMMAND = 1;
    public const int TYPE_SUB_COMMAND_GROUP = 2;

    /**
     * @param string|int|bool|null           $value   null for a sub-command; the partial input, as typed, when focused
     * @param bool                           $focused the option being typed, in an autocomplete interaction
     * @param list<DiscordInteractionOption> $options of a sub-command (group)
     */
    public function __construct(
        public string $name,
        public int $type,
        public string|int|bool|null $value = null,
        public bool $focused = false,
        public array $options = [],
    ) {
    }
}
