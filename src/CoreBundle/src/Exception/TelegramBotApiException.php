<?php

namespace App\CoreBundle\Exception;

/**
 * A Bot API call Telegram refused or that could not reach it. The message names the method, the HTTP status and
 * Telegram's description only: never the URL, which holds the bot token.
 */
class TelegramBotApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $method,
        public readonly ?int $statusCode = null,
        public readonly ?string $description = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Telegram's answer to an edit whose text and buttons are already those of the message: nothing to change.
     */
    public function isMessageNotModified(): bool
    {
        return $this->statusCode === 400 && str_contains((string) $this->description, 'message is not modified');
    }
}
