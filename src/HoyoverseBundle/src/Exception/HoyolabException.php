<?php

namespace App\HoyoverseBundle\Exception;

class HoyolabException extends \RuntimeException
{
    private ?int $hoyolabStatusCode = 200;
    private ?int $hoyolabRetcode = null;
    private ?string $hoyolabMessage = null;
    private ?array $hoyolabBody = null;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function getHoyolabStatusCode(): ?int
    {
        return $this->hoyolabStatusCode;
    }

    public function setHoyolabStatusCode(?int $hoyolabStatusCode): HoyolabException
    {
        $this->hoyolabStatusCode = $hoyolabStatusCode;
        return $this;
    }

    public function getHoyolabRetcode(): ?int
    {
        return $this->hoyolabRetcode;
    }

    public function setHoyolabRetcode(?int $hoyolabRetcode): HoyolabException
    {
        $this->hoyolabRetcode = $hoyolabRetcode;
        return $this;
    }

    public function getHoyolabMessage(): ?string
    {
        return $this->hoyolabMessage;
    }

    public function setHoyolabMessage(?string $hoyolabMessage): HoyolabException
    {
        $this->hoyolabMessage = $hoyolabMessage;
        return $this;
    }

    public function getHoyolabBody(): ?array
    {
        return $this->hoyolabBody;
    }

    public function setHoyolabBody(?array $hoyolabBody): HoyolabException
    {
        $this->hoyolabBody = $hoyolabBody;
        return $this;
    }

}