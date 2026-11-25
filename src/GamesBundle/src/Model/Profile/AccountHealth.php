<?php

namespace App\GamesBundle\Model\Profile;

/**
 * State of the login of a game account as stored on the site (no API call): a failed cookie or token refresh, an
 * expired token... The problem is a short sentence for the user, without technical details.
 */
final readonly class AccountHealth
{
    private function __construct(
        public bool $ok,
        public ?string $problem = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function problem(string $problem): self
    {
        return new self(false, $problem);
    }
}
