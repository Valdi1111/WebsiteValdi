<?php

namespace App\CoreBundle\Exception;

/**
 * The Discord account is already linked to another user: one Discord id belongs to one user at most.
 */
class DiscordAccountAlreadyLinkedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This Discord account is already linked to another user.', 0, $previous);
    }
}
