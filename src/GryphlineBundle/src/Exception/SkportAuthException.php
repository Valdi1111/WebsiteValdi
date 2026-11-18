<?php

namespace App\GryphlineBundle\Exception;

use App\CoreBundle\Exception\UserFacingHttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

class SkportAuthException extends GryphlineException implements UserFacingHttpExceptionInterface
{
    private int $httpStatusCode;
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message = 'Gryphline authentication failed.',
        int $code = 0,
        ?\Throwable $previous = null,
        int $httpStatusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
        array $headers = [],
    ) {
        parent::__construct($message, $code, $previous);
        $this->httpStatusCode = $httpStatusCode;
        $this->headers = $headers;
        $this->setApiMessage($message);
    }

    public static function fromApiStatus(?int $status, ?string $apiMessage = null): self
    {
        $fallback = ($apiMessage !== null && $apiMessage !== '') ? $apiMessage : 'Gryphline authentication failed.';

        $exception = match ($status) {
            104 => new self($apiMessage ?: 'Login failed. Incorrect email address or password.', 104, null, Response::HTTP_UNPROCESSABLE_ENTITY),
            4 => new self($apiMessage ?: 'Captcha verification failed or expired. Please try again.', 4, null, Response::HTTP_UNPROCESSABLE_ENTITY),
            3 => new self($apiMessage ?: 'Invalid or expired authentication token.', 3, null, Response::HTTP_UNPROCESSABLE_ENTITY),
            6 => new self($apiMessage ?: 'Invalid login request parameters.', 6, null, Response::HTTP_BAD_REQUEST),
            8 => new self($apiMessage ?: 'Guest accounts are not supported.', 8, null, Response::HTTP_UNPROCESSABLE_ENTITY),
            default => new self($fallback, $status ?? 0, null, Response::HTTP_UNPROCESSABLE_ENTITY),
        };

        $exception->setApiCode($status);

        return $exception;
    }

    public function getStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function setStatusCode(int $httpStatusCode): static
    {
        $this->httpStatusCode = $httpStatusCode;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param array<string, string> $headers
     */
    public function setHeaders(array $headers): static
    {
        $this->headers = $headers;

        return $this;
    }
}
