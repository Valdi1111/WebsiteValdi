<?php

namespace App\HoyoverseBundle\Model;

class RedeemableCode
{
    private ?string $code = null;

    /**
     * @var string[]
     */
    private array $rewards = [];

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): RedeemableCode
    {
        $this->code = $code;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getRewards(): array
    {
        return $this->rewards;
    }

    /**
     * @param array $rewards
     * @return $this
     */
    public function setRewards(array $rewards): RedeemableCode
    {
        $this->rewards = $rewards;
        return $this;
    }
}