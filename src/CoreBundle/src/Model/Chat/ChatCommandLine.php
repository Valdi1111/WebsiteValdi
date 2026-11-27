<?php

namespace App\CoreBundle\Model\Chat;

/**
 * A command as typed in a chat (chat:run, Telegram), read by ChatCommandLineParser. Still unchecked: the dispatcher
 * refuses an unknown command or subcommand and checks the arguments.
 */
final readonly class ChatCommandLine
{
    /**
     * @param array<string, string> $arguments by option name, in the order of the options
     * @param string|null           $botName   the "@MyBot" suffix of "/help@MyBot", without "@"
     */
    public function __construct(
        public string $command,
        public ?string $subcommand = null,
        public array $arguments = [],
        public ?string $botName = null,
    ) {
    }
}
