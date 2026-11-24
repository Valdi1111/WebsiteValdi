<?php

namespace App\CoreBundle\Exception;

/**
 * Telegram refused a message of the bot to a user: the user did not allow it ("Allow Messages" at login, or never
 * started the bot), or blocked it.
 */
class TelegramMessagesNotAllowedException extends TelegramBotException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The Telegram user does not allow the bot to message them.', 0, $previous);
    }
}
