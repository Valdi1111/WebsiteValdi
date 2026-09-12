<?php

namespace App\HoyoverseBundle\Model\Notes;

use Symfony\Component\Serializer\Attribute\SerializedName;

class HonkaiStarRailExpedition
{
    /**
     * In HSR la chiave è plurale ed è un array di icone/avatar
     * @var string[]|null
     */
    #[SerializedName('avatars')]
    private ?array $avatars = null;

    #[SerializedName('status')]
    private ?string $status = null;

    #[SerializedName('remaining_time')]
    private ?int $remainingTime = null;

    /**
     * @return string[]|null
     */
    public function getAvatars(): ?array
    {
        return $this->avatars;
    }

    /**
     * @param string[]|null $avatars
     */
    public function setAvatars(?array $avatars): self
    {
        $this->avatars = $avatars;
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

    public function isFinished(): bool
    {
        return $this->status !== null && strtolower($this->status) === 'finished';
    }
}