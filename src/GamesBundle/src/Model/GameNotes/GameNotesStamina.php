<?php

namespace App\GamesBundle\Model\GameNotes;

use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;

class GameNotesStamina
{
    private ?int $currentStamina = null;

    private ?int $maxStamina = null;

    private ?int $staminaRecoverTime = null;

    private ?\DateTimeImmutable $fullAt = null;

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

    /**
     * When the stamina is full, as an instant: set from the fetch time of the notes, so it stays right however old
     * the cached notes are (unlike getStaminaRecoveryTime(), relative to the fetch). Null when unknown.
     */
    public function getFullAt(): ?\DateTimeImmutable
    {
        return $this->fullAt;
    }

    public function setFullAt(?\DateTimeImmutable $fullAt): self
    {
        $this->fullAt = $fullAt;
        return $this;
    }

    /**
     * Seconds until the stamina is full at $now: from getFullAt() when known, else the relative recovery time.
     */
    public function getSecondsUntilFull(\DateTimeInterface $now): ?int
    {
        if ($this->fullAt !== null) {
            return max(0, $this->fullAt->getTimestamp() - $now->getTimestamp());
        }

        return $this->staminaRecoverTime;
    }

    public function isWithinThreshold(RuntimeAccountDataInterface $accountData): bool
    {
        return $this->reachesThreshold($accountData->getGameProfile()->getStaminaThreshold());
    }

    /**
     * Whether the stamina reached the threshold of a profile; a null or negative threshold means "when full".
     */
    public function reachesThreshold(?int $threshold): bool
    {
        if ($threshold === null || $threshold < 0) {
            return $this->isFull();
        }

        // An unknown current stamina never reaches the threshold (null >= 0 would be true)
        return $this->getCurrentStamina() !== null && $this->getCurrentStamina() >= $threshold;
    }

    public function isFull(): bool
    {
        return $this->getCurrentStamina() !== null
            && $this->getMaxStamina() !== null
            && $this->getCurrentStamina() >= $this->getMaxStamina();
    }
}
