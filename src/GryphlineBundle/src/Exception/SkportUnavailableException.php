<?php

namespace App\GryphlineBundle\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Gryphline/SKPort could not be reached or did not answer properly (no network, DNS, timeout, HTTP 5xx/429,
 * unreadable body).
 *
 * It says nothing about the account token: callers keep the account state (no token_refresh_failed) and try again
 * later. No previous exception is attached, because transport errors quote the request URL, which can carry the token.
 */
class SkportUnavailableException extends SkportAuthException
{
    public function __construct(string $message = 'Gryphline could not be reached. Please try again later.')
    {
        parent::__construct($message, 0, null, Response::HTTP_BAD_GATEWAY);
    }

    public static function isUnavailableStatus(int $statusCode): bool
    {
        return $statusCode >= 500 || $statusCode === 429;
    }
}
