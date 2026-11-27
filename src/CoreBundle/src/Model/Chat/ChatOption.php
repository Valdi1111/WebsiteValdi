<?php

namespace App\CoreBundle\Model\Chat;

/**
 * An option of a command or subcommand. Autocomplete and fixed choices exclude each other (Discord).
 */
final readonly class ChatOption
{
    /**
     * @param list<ChatChoice> $choices
     */
    public function __construct(
        public string $name,
        public string $description,
        public ChatOptionType $type = ChatOptionType::String,
        public bool $required = false,
        public bool $autocomplete = false,
        public array $choices = [],
    ) {
    }
}
