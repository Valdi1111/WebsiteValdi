<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Stygian Onslaught record of a period in one way of playing, solo (single) or co-op (mp). Without a record:
 * has_data false, best null, challenge empty (seen for both).
 */
class StygianOnslaughtRecord
{
    #[SerializedName('has_data')]
    private ?bool $hasData = null;

    #[SerializedName('best')]
    private ?StygianOnslaughtBest $best = null;

    public function getHasData(): ?bool
    {
        return $this->hasData;
    }

    public function setHasData(?bool $hasData): static
    {
        $this->hasData = $hasData;
        return $this;
    }

    public function getBest(): ?StygianOnslaughtBest
    {
        return $this->best;
    }

    public function setBest(?StygianOnslaughtBest $best): static
    {
        $this->best = $best;
        return $this;
    }
}
