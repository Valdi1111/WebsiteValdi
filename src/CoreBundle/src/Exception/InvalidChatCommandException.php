<?php

namespace App\CoreBundle\Exception;

/**
 * A chat command defined against the rules of a platform (names, lengths, option order...): a programming error, found
 * when the definition is built (ChatCommandBuilder) and by the wiring test over every registered command.
 */
class InvalidChatCommandException extends \LogicException
{
}
