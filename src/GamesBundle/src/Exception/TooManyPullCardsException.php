<?php

namespace App\GamesBundle\Exception;

use App\CoreBundle\Exception\UserFacingHttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Too many shareable pulls cards in a short time: the rate limiter (games_pull_card) refused this one. A 429 whose
 * message is shown to the user, also in production.
 */
class TooManyPullCardsException extends \RuntimeException implements UserFacingHttpExceptionInterface
{
    public const string MESSAGE = 'Too many images generated: try again in a few minutes.';

    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct(self::MESSAGE);
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
