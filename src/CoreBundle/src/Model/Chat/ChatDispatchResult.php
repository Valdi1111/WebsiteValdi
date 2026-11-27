<?php

namespace App\CoreBundle\Model\Chat;

/**
 * Outcome of the synchronous phase of a platform request (ChatDispatcherInterface): an answer to give at once (not
 * linked, unknown command, missing role, refused button: always only for the user), or the work queued, with the
 * visibility the platform must acknowledge it with.
 */
final readonly class ChatDispatchResult
{
    private function __construct(
        public ?ChatReply $reply,
        public bool $ephemeral,
    ) {
    }

    public static function immediate(ChatReply $reply): self
    {
        return new self($reply, true);
    }

    public static function deferred(bool $ephemeral): self
    {
        return new self(null, $ephemeral);
    }

    public function isDeferred(): bool
    {
        return $this->reply === null;
    }
}
