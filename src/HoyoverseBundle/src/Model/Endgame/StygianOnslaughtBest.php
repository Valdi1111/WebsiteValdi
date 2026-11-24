<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Best result of the player in Stygian Onslaught for a period, solo or co-op (hard_challenge data[].single.best,
 * data[].mp.best): the difficulty and the time of the run.
 */
class StygianOnslaughtBest
{
    /** difficulty_id, see StygianOnslaughtType::DIFFICULTIES */
    #[SerializedName('difficulty')]
    private ?int $difficulty = null;

    /** Time of the run, in seconds */
    #[SerializedName('second')]
    private ?int $second = null;

    /** Medal of the difficulty, e.g. "UI_LeyLineChallenge_Medal_4" (a name, not a URL) */
    #[SerializedName('icon')]
    private ?string $icon = null;

    public function getDifficulty(): ?int
    {
        return $this->difficulty;
    }

    public function setDifficulty(?int $difficulty): static
    {
        $this->difficulty = $difficulty;
        return $this;
    }

    public function getSecond(): ?int
    {
        return $this->second;
    }

    public function setSecond(?int $second): static
    {
        $this->second = $second;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }
}
