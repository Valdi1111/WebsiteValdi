<?php

namespace App\HoyoverseBundle\Model\Notes;

use App\HoyoverseBundle\Model\RuntimeAccountData;

class GameNotesRealm
{
    private ?int $currentCoin = null;

    private ?int $maxCoin = null;

    private ?int $coinRecoverTime = null;

    public function getCurrentCoin(): ?int
    {
        return $this->currentCoin;
    }

    public function setCurrentCoin(?int $currentCoin): self
    {
        $this->currentCoin = $currentCoin;
        return $this;
    }

    public function getMaxCoin(): ?int
    {
        return $this->maxCoin;
    }

    public function setMaxCoin(?int $maxCoin): self
    {
        $this->maxCoin = $maxCoin;
        return $this;
    }

    public function getCoinRecoveryTime(): ?int
    {
        return $this->coinRecoverTime;
    }

    public function setCoinRecoverTime(?int $coinRecoverTime): self
    {
        $this->coinRecoverTime = $coinRecoverTime;
        return $this;
    }

    public function isWithinThreshold(RuntimeAccountData $accountData): bool
    {
        return $this->reachesThreshold($accountData->getGameProfile()->getRealmCurrencyThreshold());
    }

    /**
     * Whether the realm currency reached the threshold of a profile; a null or negative threshold means "when full".
     * An unknown current amount never reaches it.
     */
    public function reachesThreshold(?int $threshold): bool
    {
        if ($this->getCurrentCoin() === null) {
            return false;
        }
        if ($threshold === null || $threshold < 0) {
            return $this->isFull();
        }

        return $this->getCurrentCoin() >= $threshold;
    }

    /**
     * Full only when both the current and the max amount are known.
     */
    public function isFull(): bool
    {
        return $this->getCurrentCoin() !== null
            && $this->getMaxCoin() !== null
            && $this->getCurrentCoin() >= $this->getMaxCoin();
    }
}
