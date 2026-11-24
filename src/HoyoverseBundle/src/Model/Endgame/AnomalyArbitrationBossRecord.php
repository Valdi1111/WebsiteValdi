<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Record of the King of an Anomaly Arbitration run (boss_record). Null in the response when the King was not fought.
 */
class AnomalyArbitrationBossRecord
{
    /** Plight (hard mode) cleared. Plight has no stars: it is passed or it is not */
    #[SerializedName('hard_mode')]
    private ?bool $hardMode = null;

    #[SerializedName('star_num')]
    private int|string|null $starNum = null;

    public function getHardMode(): ?bool
    {
        return $this->hardMode;
    }

    public function setHardMode(?bool $hardMode): static
    {
        $this->hardMode = $hardMode;
        return $this;
    }

    public function getStarNum(): int|string|null
    {
        return $this->starNum;
    }

    public function setStarNum(int|string|null $starNum): static
    {
        $this->starNum = $starNum;
        return $this;
    }
}
