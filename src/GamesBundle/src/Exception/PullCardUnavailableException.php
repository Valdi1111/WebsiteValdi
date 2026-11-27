<?php

namespace App\GamesBundle\Exception;

use App\CoreBundle\Exception\UserFacingHttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shareable pulls card could not be rendered (node-services down, refused or too slow). A 503 whose message is
 * shown to the user, also in production; the cause stays in the logs.
 */
class PullCardUnavailableException extends \RuntimeException implements UserFacingHttpExceptionInterface
{
    public const string MESSAGE = 'The image could not be generated right now: try again in a few minutes.';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_SERVICE_UNAVAILABLE;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
