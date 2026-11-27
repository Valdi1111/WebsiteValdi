<?php

namespace App\CoreBundle\Model\Chat;

/**
 * A command as a user asked it, already checked (linked user, known command, role).
 */
final readonly class ChatRequest
{
    /**
     * @param array<string, string|int|bool> $arguments by option name, only the options given
     */
    public function __construct(
        public ChatPlatform $platform,
        public ChatUser $user,
        public string $command,
        public ?string $subcommand = null,
        public array $arguments = [],
        public string $locale = 'en',
    ) {
    }

    public function get(string $option, string|int|bool|null $default = null): string|int|bool|null
    {
        return $this->arguments[$option] ?? $default;
    }
}
