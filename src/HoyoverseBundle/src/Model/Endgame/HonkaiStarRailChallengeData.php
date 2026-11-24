<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Current period of Memory of Chaos (challenge), Pure Fiction (challenge_story) or Apocalyptic Shadow
 * (challenge_boss), requested with schedule_type=1 and need_all=true: the three share one shape.
 *
 * The rules of the Endgames Reminder (played, maximum, Starward Mode) are the constants and methods below. The
 * response of a player who did not play has has_data false, no stages and no stars, and does not say which period it
 * is: the dates come from groups[].
 */
class HonkaiStarRailChallengeData
{
    /** Stars of Memory of Chaos at the maximum: twelve stages with three stars each */
    public const int MEMORY_OF_CHAOS_MAX_STARS = 36;

    /** Stars of Pure Fiction and Apocalyptic Shadow at the maximum: four stages with three stars each */
    public const int STORY_MAX_STARS = 12;

    /** Prismatic Stars of Starward Mode at the maximum */
    public const int STARWARD_MAX_STARS = 1;

    /** Stars of the period; the Prismatic Star is not counted (the stages add up to one more) */
    #[SerializedName('star_num')]
    private int|string|null $starNum = null;

    /** Prismatic Stars of Starward Mode */
    #[SerializedName('extra_star_num')]
    private int|string|null $extraStarNum = null;

    /** Battles fought in the period: quick-cleared stages are not battles */
    #[SerializedName('battle_num')]
    private int|string|null $battleNum = null;

    #[SerializedName('has_data')]
    private ?bool $hasData = null;

    /**
     * @var HonkaiStarRailEndgamePeriod[]|null
     */
    #[SerializedName('groups')]
    private ?array $periods = null;

    /**
     * @var HonkaiStarRailChallengeFloor[]|null
     */
    #[SerializedName('all_floor_detail')]
    private ?array $floors = null;

    public function getStarNum(): int|string|null
    {
        return $this->starNum;
    }

    public function setStarNum(int|string|null $starNum): static
    {
        $this->starNum = $starNum;
        return $this;
    }

    public function getExtraStarNum(): int|string|null
    {
        return $this->extraStarNum;
    }

    public function setExtraStarNum(int|string|null $extraStarNum): static
    {
        $this->extraStarNum = $extraStarNum;
        return $this;
    }

    public function getBattleNum(): int|string|null
    {
        return $this->battleNum;
    }

    public function setBattleNum(int|string|null $battleNum): static
    {
        $this->battleNum = $battleNum;
        return $this;
    }

    public function getHasData(): ?bool
    {
        return $this->hasData;
    }

    public function setHasData(?bool $hasData): static
    {
        $this->hasData = $hasData;
        return $this;
    }

    /**
     * @return HonkaiStarRailEndgamePeriod[]|null
     */
    public function getPeriods(): ?array
    {
        return $this->periods;
    }

    /**
     * @param HonkaiStarRailEndgamePeriod[]|null $periods
     */
    public function setPeriods(?array $periods): static
    {
        $this->periods = $periods;
        return $this;
    }

    /**
     * @return HonkaiStarRailChallengeFloor[]|null
     */
    public function getFloors(): ?array
    {
        return $this->floors;
    }

    /**
     * @param HonkaiStarRailChallengeFloor[]|null $floors
     */
    public function setFloors(?array $floors): static
    {
        $this->floors = $floors;
        return $this;
    }

    public function getStars(): int
    {
        return max(0, (int) ($this->starNum ?? 0));
    }

    public function getExtraStars(): int
    {
        return max(0, (int) ($this->extraStarNum ?? 0));
    }

    /**
     * Played = at least one battle: a quick-cleared stage carries stars without one (assumption, see the pull
     * request: the quick clears credited from the previous period leave battle_num at 0).
     */
    public function isPlayed(): bool
    {
        return (int) ($this->battleNum ?? 0) > 0;
    }

    /**
     * Maximum = every star of the mode, Prismatic Star excluded.
     */
    public function isMaxed(int $maxStars): bool
    {
        return $this->getStars() >= $maxStars;
    }

    /**
     * Starward Mode played = a stage played with three teams (is_tierce), or the Prismatic Star in hand (in case a
     * final stage played in normal mode looks different from one played in Starward Mode).
     */
    public function isStarwardPlayed(): bool
    {
        if ($this->getExtraStars() > 0) {
            return true;
        }

        foreach ($this->floors ?? [] as $floor) {
            if ($floor->isStarwardPlayed()) {
                return true;
            }
        }

        return false;
    }

    public function isStarwardMaxed(): bool
    {
        return $this->getExtraStars() >= self::STARWARD_MAX_STARS;
    }
}
