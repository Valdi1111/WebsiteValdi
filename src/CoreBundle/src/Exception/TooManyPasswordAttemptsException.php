<?php

namespace App\CoreBundle\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Too many attempts to change the password with a current password: the rate limiter (core_password_change) refused
 * this one. A 429 whose message is shown to the user, also in production.
 */
class TooManyPasswordAttemptsException extends \RuntimeException implements UserFacingHttpExceptionInterface
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct('Too many attempts: try again in a few minutes.');
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_TOO_MANY_REQUESTS;
    }

    public function getHeaders(): array
    {
        return ['Retry-After' => (string) max(1, $this->retryAfter->getTimestamp() - time())];
    }
}
