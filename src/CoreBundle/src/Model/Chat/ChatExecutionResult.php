<?php

namespace App\CoreBundle\Model\Chat;

/**
 * The answer of a command or a button once run (ChatExecutor), or of a request refused before running it (chat:run).
 * $failed tells the error answers apart without reading their text: a failed button press is sent as a follow-up, so
 * the error never replaces the message the button belongs to.
 */
final readonly class ChatExecutionResult
{
    private function __construct(
        public ChatReply $reply,
        public bool $failed,
    ) {
    }

    public static function succeeded(ChatReply $reply): self
    {
        return new self($reply, false);
    }

    /**
     * @param ChatReply $reply a refusal or ChatReply::error(), only for the user
     */
    public static function failed(ChatReply $reply): self
    {
        return new self($reply, true);
    }
}
