<?php

namespace App\CoreBundle\Exception;

/**
 * The Discord OAuth2 flow failed: the code could not be exchanged or the user could not be read.
 * The message is for the logs, not for the user.
 */
class DiscordOAuthException extends \RuntimeException
{
}
