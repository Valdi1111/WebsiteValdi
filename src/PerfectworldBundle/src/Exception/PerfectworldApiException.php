<?php

namespace App\PerfectworldBundle\Exception;

use App\CoreBundle\Exception\UserFacingHttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Error returned by (or while calling) the Perfect World web SDK.
 *
 * Messages are user-facing and never contain request URLs: email, login code and token travel in
 * the query string, so the original transport exception is deliberately not chained.
 */
class PerfectworldApiException extends \RuntimeException implements UserFacingHttpExceptionInterface
{
    public function __construct(
        string $message = 'Perfect World request failed.',
        private readonly ?int $apiCode = null,
        private readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message, $apiCode ?? 0);
    }

    public function getApiCode(): ?int
    {
        return $this->apiCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
