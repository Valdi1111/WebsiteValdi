<?php

namespace App\CoreBundle\Model\Chat;

/**
 * A command as every platform registers and parses it: from #[AsChatCommand] and ChatCommandInterface::configure().
 * A command has either options or subcommands, never both (Discord).
 */
final readonly class ChatCommandDefinition
{
    /**
     * @param list<ChatOption>               $options
     * @param list<ChatSubcommandDefinition> $subcommands
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $role = null,
        public bool $ephemeral = true,
        public array $options = [],
        public array $subcommands = [],
    ) {
    }

    public function getSubcommand(string $name): ?ChatSubcommandDefinition
    {
        foreach ($this->subcommands as $subcommand) {
            if ($subcommand->name === $name) {
                return $subcommand;
            }
        }

        return null;
    }
}
