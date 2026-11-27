<?php

namespace App\CoreBundle\Attribute;

/**
 * A chat command (Discord slash command, Telegram /command, chat:run), on a class implementing ChatCommandInterface.
 * Autoconfigured with the tag core.chat_command (CoreBundle::loadExtension()); ChatCommandRegistry reads this attribute
 * from the class, so listing the commands never builds them.
 *
 * The name follows the strictest platform rule: 1 to 32 characters among lowercase letters, digits and "_" (Telegram).
 * The description is the English text, also the translation key of the "chat" domain for the other locales.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsChatCommand
{
    public const string TAG = 'core.chat_command';

    /**
     * @param string|null $role      role the user needs, assigned or inherited; null = any linked user
     * @param bool        $ephemeral whether only the user sees the answer (fixed when Discord acknowledges the command)
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $role = null,
        public bool $ephemeral = true,
    ) {
    }
}
