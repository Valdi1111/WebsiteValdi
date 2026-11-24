<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One floor of the Spiral Abyss played in the period (floors[] lists only the floors played, not the skipped ones).
 */
class SpiralAbyssFloor
{
    /** Stars of a floor at the maximum: three chambers with three stars each */
    public const int MAX_STARS = 9;

    /** Floor number, e.g. 12 */
    #[SerializedName('index')]
    private ?int $index = null;

    #[SerializedName('star')]
    private ?int $star = null;

    #[SerializedName('max_star')]
    private ?int $maxStar = null;

    #[SerializedName('is_unlock')]
    private ?bool $isUnlock = null;

    public function getIndex(): ?int
    {
        return $this->index;
    }

    public function setIndex(?int $index): static
    {
        $this->index = $index;
        return $this;
    }

    public function getStar(): ?int
    {
        return $this->star;
    }

    public function setStar(?int $star): static
    {
        $this->star = $star;
        return $this;
    }

    public function getMaxStar(): ?int
    {
        return $this->maxStar;
    }

    public function setMaxStar(?int $maxStar): static
    {
        $this->maxStar = $maxStar;
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
     * Stars a floor can give: max_star, or nine when the response does not say.
     */
    public function getStarsAvailable(): int
    {
        return $this->getMaxStar() ?: self::MAX_STARS;
    }

    public function isFullyCleared(): bool
    {
        return ($this->getStar() ?? 0) >= $this->getStarsAvailable();
    }
}
