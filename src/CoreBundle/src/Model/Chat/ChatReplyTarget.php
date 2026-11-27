<?php

namespace App\CoreBundle\Model\Chat;

/**
 * Where the worker sends the answer of a deferred request, kept in the Messenger message. For Discord: the application
 * id and the interaction token, which lasts 15 minutes and is a credential (never logged). For the CLI: nothing.
 */
final readonly class ChatReplyTarget
{
    /**
     * @param array<string, string> $data platform specific
     */
    public function __construct(
        public ChatPlatform $platform,
        #[\SensitiveParameter]
        public array $data = [],
        public \DateTimeImmutable $receivedAt = new \DateTimeImmutable(),
    ) {
    }

    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }
}
