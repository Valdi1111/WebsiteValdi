<?php

namespace App\CoreBundle\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A signed interaction the bot cannot read: malformed body, unknown type, or a field its type needs is missing.
 * Answered with 400. The message never holds the interaction token.
 */
class InvalidDiscordInteractionException extends BadRequestHttpException
{
}
