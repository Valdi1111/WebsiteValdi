<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One stage of Memory of Chaos, Pure Fiction or Apocalyptic Shadow (all_floor_detail[]), highest stage first.
 *
 * Stars come as numbers, or as strings in Apocalyptic Shadow. The stage played in Starward Mode (is_tierce) has the
 * Prismatic Star on top of its three stars: star_num counts it, so the base stars are star_num minus extra_star_num.
 */
class HonkaiStarRailChallengeFloor
{
    /** Stars of a stage at the maximum, Prismatic Star excluded */
    public const int MAX_STARS = 3;

    /** Suffix glued to the name of the stage played in Starward Mode (no space: "… (XII)Starward Mode") */
    private const string STARWARD_SUFFIX = 'Starward Mode';

    #[SerializedName('name')]
    private ?string $name = null;

    #[SerializedName('star_num')]
    private int|string|null $starNum = null;

    #[SerializedName('extra_star_num')]
    private int|string|null $extraStarNum = null;

    /** Quick clear: the stars were credited without a battle in this period */
    #[SerializedName('is_fast')]
    private ?bool $fast = null;

    /** Played in Starward Mode (three teams) */
    #[SerializedName('is_tierce')]
    private ?bool $tierce = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
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

    public function getExtraStarNum(): int|string|null
    {
        return $this->extraStarNum;
    }

    public function setExtraStarNum(int|string|null $extraStarNum): static
    {
        $this->extraStarNum = $extraStarNum;
        return $this;
    }

    public function isFast(): ?bool
    {
        return $this->fast;
    }

    public function setFast(?bool $fast): static
    {
        $this->fast = $fast;
        return $this;
    }

    public function isTierce(): ?bool
    {
        return $this->tierce;
    }

    public function setTierce(?bool $tierce): static
    {
        $this->tierce = $tierce;
        return $this;
    }

    /**
     * Prismatic Stars of the stage (0 or 1).
     */
    public function getExtraStars(): int
    {
        return max(0, (int) ($this->extraStarNum ?? 0));
    }

    /**
     * Stars of the stage without the Prismatic Star.
     */
    public function getBaseStars(): int
    {
        return max(0, (int) ($this->starNum ?? 0) - $this->getExtraStars());
    }

    /**
     * The stage was played in Starward Mode: flagged by the game, or holding the Prismatic Star.
     */
    public function isStarwardPlayed(): bool
    {
        return $this->isTierce() === true || $this->getExtraStars() > 0;
    }

    /**
     * Name of the stage as it is shown in the game, without the Starward Mode suffix; the whole name when nothing
     * is left of it. Never the position in the list: a period lists only the stages that were reached.
     */
    public function getLabel(): ?string
    {
        $name = trim($this->name ?? '');
        if (str_ends_with($name, self::STARWARD_SUFFIX)) {
            $name = trim(substr($name, 0, -strlen(self::STARWARD_SUFFIX)));
        }

        return $name !== '' ? $name : (trim($this->name ?? '') ?: null);
    }

    public function isFullyCleared(): bool
    {
        return $this->getBaseStars() >= self::MAX_STARS;
    }
}
