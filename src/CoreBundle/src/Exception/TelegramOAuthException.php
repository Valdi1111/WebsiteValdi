<?php

namespace App\CoreBundle\Exception;

/**
 * The Telegram login (OpenID Connect) failed: the code could not be exchanged or the id token is not valid.
 * The message is for the logs, not for the user.
 */
class TelegramOAuthException extends \RuntimeException
{
}
