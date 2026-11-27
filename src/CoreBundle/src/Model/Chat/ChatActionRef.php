<?php

namespace App\CoreBundle\Model\Chat;

/**
 * What a button does: its handler (#[AsChatAction] name) and compact arguments, encoded by ChatActionCodec as
 * "handler:arg1:arg2" in at most 64 bytes (Telegram callback_data). The arguments are never trusted: the handler checks
 * them against the user on every press (ChatActionHandlerInterface::authorize()), and they never hold secrets.
 */
final readonly class ChatActionRef
{
    /**
     * @param list<string> $args
     */
    public function __construct(
        public string $handler,
        public array $args = [],
    ) {
    }
}
