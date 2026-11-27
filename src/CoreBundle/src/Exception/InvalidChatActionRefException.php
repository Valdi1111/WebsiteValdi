<?php

namespace App\CoreBundle\Exception;

/**
 * A ChatActionRef that cannot be encoded (a programming error: ":" in an argument, over 64 bytes...) or button data
 * that cannot be decoded (sent by the platform, so never trusted: the dispatcher refuses the press).
 */
class InvalidChatActionRefException extends \InvalidArgumentException
{
}
