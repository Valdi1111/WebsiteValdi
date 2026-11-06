<?php

namespace App\CoreBundle\Model\GameNotes;

use App\CoreBundle\Model\Game\RuntimeAccountDataInterface;

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

    public function isWithinThreshold(RuntimeAccountDataInterface $accountData): bool
    {
        $profile = $accountData->getGameProfile();
        $threshold = $profile->getStaminaThreshold();
        if ($threshold === null || $threshold < 0) {
            return $this->isFull();
        }

        return $this->getCurrentStamina() >= $threshold;
    }

    public function isFull(): bool
    {
        return $this->getCurrentStamina() !== null
            && $this->getMaxStamina() !== null
            && $this->getCurrentStamina() >= $this->getMaxStamina();
    }
}
