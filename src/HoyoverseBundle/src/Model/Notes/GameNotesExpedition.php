<?php

namespace App\HoyoverseBundle\Model\Notes;

class GameNotesExpedition
{
    private ?string $avatar = null;
    private ?string $status = null;
    private ?int $remainingTime = null;

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): self
    {
        $this->avatar = $avatar;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getRemainingTime(): ?int
    {
        return $this->remainingTime;
    }

    public function setRemainingTime(?int $remainingTime): self
    {
        $this->remainingTime = $remainingTime;
        return $this;
    }

    public function isDone(): bool
    {
        return $this->getStatus() === 'finished';
    }
}