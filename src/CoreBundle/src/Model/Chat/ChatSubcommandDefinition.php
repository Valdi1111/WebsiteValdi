<?php

namespace App\CoreBundle\Model\Chat;

final readonly class ChatSubcommandDefinition
{
    /**
     * @param list<ChatOption> $options
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $options = [],
    ) {
    }
}
