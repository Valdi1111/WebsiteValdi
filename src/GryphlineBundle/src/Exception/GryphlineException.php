<?php

namespace App\GryphlineBundle\Exception;

class GryphlineException extends \RuntimeException
{
    private ?int $apiStatusCode = 200;
    private ?int $apiCode = null;
    private ?string $apiMessage = null;
    private ?array $responseBody = null;

    public function getApiStatusCode(): ?int
    {
        return $this->apiStatusCode;
    }

    public function setApiStatusCode(?int $apiStatusCode): static
    {
        $this->apiStatusCode = $apiStatusCode;
        return $this;
    }

    public function getApiCode(): ?int
    {
        return $this->apiCode;
    }

    public function setApiCode(?int $apiCode): static
    {
        $this->apiCode = $apiCode;
        return $this;
    }

    public function getApiMessage(): ?string
    {
        return $this->apiMessage;
    }

    public function setApiMessage(?string $apiMessage): static
    {
        $this->apiMessage = $apiMessage;
        return $this;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    public function setResponseBody(?array $responseBody): static
    {
        $this->responseBody = $responseBody;
        return $this;
    }
}
