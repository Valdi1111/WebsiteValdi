<?php

namespace App\CoreBundle\Exception;

/**
 * The bot could not send a message (TelegramBot). The message is for the logs, not for the user.
 */
class TelegramBotException extends \RuntimeException
{
}
