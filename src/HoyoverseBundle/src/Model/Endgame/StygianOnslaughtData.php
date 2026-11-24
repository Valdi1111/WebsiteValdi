<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Stygian Onslaught of the player (game_record/genshin/api/hard_challenge): the current and the previous period, with
 * a gap between two periods. The schedule has no schedule_type: the current period is the one containing now.
 */
class StygianOnslaughtData
{
    /**
     * @var StygianOnslaughtType[]|null
     */
    #[SerializedName('data')]
    private ?array $data = null;

    #[SerializedName('is_unlock')]
    private ?bool $isUnlock = null;

    /**
     * @return StygianOnslaughtType[]|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @param StygianOnslaughtType[]|null $data
     */
    public function setData(?array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function getIsUnlock(): ?bool
    {
        return $this->isUnlock;
    }

    public function setIsUnlock(?bool $isUnlock): static
    {
        $this->isUnlock = $isUnlock;
        return $this;
    }

    /**
     * Locked only when the response says so (is_unlock false).
     */
    public function isUnlocked(): bool
    {
        return $this->getIsUnlock() !== false;
    }

    /**
     * The period whose schedule contains $now, null between two periods (or when the response has none).
     */
    public function getPeriodAt(\DateTimeInterface $now): ?StygianOnslaughtType
    {
        foreach ($this->getData() ?? [] as $period) {
            if ($period->getSchedule()?->contains($now)) {
                return $period;
            }
        }

        return null;
    }
}
