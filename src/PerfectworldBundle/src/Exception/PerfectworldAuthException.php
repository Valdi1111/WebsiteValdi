<?php

namespace App\PerfectworldBundle\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The SDK token is invalid or expired (API code 20001): the account must log in again.
 */
class PerfectworldAuthException extends PerfectworldApiException
{
    public const int CODE_TOKEN_EXPIRED = 20001;

    public function __construct(
        string $message = 'Login expired, please log in again.',
        int $statusCode = Response::HTTP_CONFLICT,
    ) {
        parent::__construct($message, self::CODE_TOKEN_EXPIRED, $statusCode);
    }
}
