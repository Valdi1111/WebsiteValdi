<?php

namespace App\HoyoverseBundle\Exception;

class HoyolabException extends \RuntimeException
{
    private ?int $hoyolabStatusCode = 200;
    private ?int $hoyolabRetcode = null;
    private ?string $hoyolabMessage = null;
    private ?array $hoyolabBody = null;

    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getHoyolabStatusCode(): ?int
    {
        return $this->hoyolabStatusCode;
    }

    public function setHoyolabStatusCode(?int $hoyolabStatusCode): static
    {
        $this->hoyolabStatusCode = $hoyolabStatusCode;
        return $this;
    }

    public function getHoyolabRetcode(): ?int
    {
        return $this->hoyolabRetcode;
    }

    public function setHoyolabRetcode(?int $hoyolabRetcode): static
    {
        $this->hoyolabRetcode = $hoyolabRetcode;
        return $this;
    }

    public function getHoyolabMessage(): ?string
    {
        return $this->hoyolabMessage;
    }

    public function setHoyolabMessage(?string $hoyolabMessage): static
    {
        $this->hoyolabMessage = $hoyolabMessage;
        return $this;
    }

    public function getHoyolabBody(): ?array
    {
        return $this->hoyolabBody;
    }

    public function setHoyolabBody(?array $hoyolabBody): static
    {
        $this->hoyolabBody = $hoyolabBody;
        return $this;
    }
}