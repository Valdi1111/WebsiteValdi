<?php

namespace App\HoyoverseBundle\Exception;

use App\CoreBundle\Exception\UserFacingHttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

class HoyoverseAuthException extends HoyolabException implements UserFacingHttpExceptionInterface
{
    private int $httpStatusCode;
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message = 'HoYoverse authentication failed.',
        int $httpStatusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
        ?int $retcode = null,
        ?\Throwable $previous = null,
        array $headers = [],
    ) {
        parent::__construct($message, $retcode ?? 0, $previous);
        $this->httpStatusCode = $httpStatusCode;
        $this->headers = $headers;
        if ($retcode !== null) {
            $this->setHoyolabRetcode($retcode);
        }
        $this->setHoyolabMessage($message);
    }

    public static function fromRetcode(?int $retcode, ?string $apiMessage = null): self
    {
        $fallback = $apiMessage !== null && $apiMessage !== '' ? $apiMessage : 'HoYoverse authentication failed.';

        return match ($retcode) {
            -3004, -3208 => new self($apiMessage ?: 'Invalid account or password.', Response::HTTP_UNPROCESSABLE_ENTITY, $retcode),
            -3203 => new self($apiMessage ?: 'Account does not exist.', Response::HTTP_UNPROCESSABLE_ENTITY, $retcode),
            -3202 => new self($apiMessage ?: 'Account is locked or restricted.', Response::HTTP_UNPROCESSABLE_ENTITY, $retcode),
            -3205 => new self($apiMessage ?: 'Invalid or expired verification code.', Response::HTTP_UNPROCESSABLE_ENTITY, $retcode),
            -3102 => new self($apiMessage ?: 'Captcha verification failed. Please try again.', Response::HTTP_UNPROCESSABLE_ENTITY, $retcode),
            -3206, -3006, -119 => new self($apiMessage ?: 'Too many attempts. Please wait before trying again.', Response::HTTP_TOO_MANY_REQUESTS, $retcode),
            default => new self($fallback, Response::HTTP_UNPROCESSABLE_ENTITY, $retcode),
        };
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
