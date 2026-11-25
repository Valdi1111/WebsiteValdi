<?php

namespace App\HoyoverseBundle\Exception;

class ConfigurationException extends \RuntimeException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The HoYoLAB cookie of an account is missing or lacks the basic tokens. The message names only the account:
     * the cookie holds its credentials and must never reach logs, failed messages or the pages.
     */
    public static function invalidCookie(?int $accountId): self
    {
        return new self(sprintf('The HoYoLAB cookie of account #%s is missing or invalid.', $accountId ?? '?'));
    }

}
