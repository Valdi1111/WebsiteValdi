<?php

namespace App\HoyoverseBundle\Model\Notes;

use App\HoyoverseBundle\Model\RuntimeAccountData;

class GameNotesStamina
{
    private ?int $currentStamina = null;

    private ?int $maxStamina = null;

    private ?int $staminaRecoverTime = null;

    public function getCurrentStamina(): ?int
    {
        return $this->currentStamina;
    }

    public function setCurrentStamina(?int $currentStamina): self
    {
        $this->currentStamina = $currentStamina;
        return $this;
    }

    public function getMaxStamina(): ?int
    {
        return $this->maxStamina;
    }

    public function setMaxStamina(?int $maxStamina): self
    {
        $this->maxStamina = $maxStamina;
        return $this;
    }

    public function getStaminaRecoveryTime(): ?int
    {
        return $this->staminaRecoverTime;
    }

    public function setStaminaRecoverTime(?int $staminaRecoverTime): self
    {
        $this->staminaRecoverTime = $staminaRecoverTime;
        return $this;
    }

    public function isWithinThreshold(RuntimeAccountData $accountData): bool
    {
        return $this->getCurrentStamina() >= $accountData->getGameProfile()->getStaminaThreshold();
    }

    public function isFull(): bool
    {
        return $this->getCurrentStamina() == $this->getMaxStamina();
    }
}