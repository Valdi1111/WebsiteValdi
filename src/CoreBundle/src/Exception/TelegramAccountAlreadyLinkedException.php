<?php

namespace App\CoreBundle\Exception;

/**
 * The Telegram account is already linked to another user: one Telegram id belongs to one user at most.
 */
class TelegramAccountAlreadyLinkedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This Telegram account is already linked to another user.', 0, $previous);
    }
}
