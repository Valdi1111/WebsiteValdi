<?php

namespace App\CoreBundle\Exception;

/**
 * A typed command line (chat:run, Telegram) that cannot be read: empty, or more arguments than the command has options.
 * The message is meant for the user.
 */
class InvalidChatCommandLineException extends \InvalidArgumentException
{
}
