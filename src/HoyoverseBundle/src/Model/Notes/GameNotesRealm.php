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
        if ($accountData->getGameProfile()->getRealmCurrencyThreshold() < 0) {
            return false;
        }
        return $this->getCurrentCoin() >= $accountData->getGameProfile()->getRealmCurrencyThreshold();
    }

    public function isFull(): bool
    {
        return $this->getCurrentCoin() == $this->getMaxCoin();
    }
}