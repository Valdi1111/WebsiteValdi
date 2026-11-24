<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Spiral Abyss period of the player (game_record/genshin/api/spiralAbyss), start and end at the top level.
 *
 * The rules of the Endgames Reminder (unlocked, played, maximum) are the constants and methods below.
 */
class SpiralAbyssData extends ScheduleData
{
    /** All the stars of a period: floors 9 to 12, nine stars each (floors 9 and 10 can be skipped, with their stars) */
    public const int MAX_STARS = 36;

    #[SerializedName('total_battle_times')]
    private ?int $totalBattleTimes = null;

    #[SerializedName('max_floor')]
    private ?string $maxFloor = null;

    #[SerializedName('total_star')]
    private ?int $totalStar = null;

    /**
     * @var SpiralAbyssFloor[]|null
     */
    #[SerializedName('floors')]
    private ?array $floors = null;

    #[SerializedName('is_unlock')]
    private ?bool $isUnlock = null;

    #[SerializedName('is_just_skipped_floor')]
    private ?bool $isJustSkippedFloor = null;

    #[SerializedName('skipped_floor')]
    private ?string $skippedFloor = null;

    public function getTotalBattleTimes(): ?int
    {
        return $this->totalBattleTimes;
    }

    public function setTotalBattleTimes(?int $totalBattleTimes): static
    {
        $this->totalBattleTimes = $totalBattleTimes;
        return $this;
    }

    public function getMaxFloor(): ?string
    {
        return $this->maxFloor;
    }

    public function setMaxFloor(?string $maxFloor): static
    {
        $this->maxFloor = $maxFloor;
        return $this;
    }

    public function getTotalStar(): ?int
    {
        return $this->totalStar;
    }

    public function setTotalStar(?int $totalStar): static
    {
        $this->totalStar = $totalStar;
        return $this;
    }

    /**
     * @return SpiralAbyssFloor[]|null
     */
    public function getFloors(): ?array
    {
        return $this->floors;
    }

    /**
     * @param SpiralAbyssFloor[]|null $floors
     */
    public function setFloors(?array $floors): static
    {
        $this->floors = $floors;
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

    public function getIsJustSkippedFloor(): ?bool
    {
        return $this->isJustSkippedFloor;
    }

    public function setIsJustSkippedFloor(?bool $isJustSkippedFloor): static
    {
        $this->isJustSkippedFloor = $isJustSkippedFloor;
        return $this;
    }

    public function getSkippedFloor(): ?string
    {
        return $this->skippedFloor;
    }

    public function setSkippedFloor(?string $skippedFloor): static
    {
        $this->skippedFloor = $skippedFloor;
        return $this;
    }

    /**
     * Locked only when the response says so (is_unlock false, seen on an account that has not reached it yet).
     */
    public function isUnlocked(): bool
    {
        return $this->getIsUnlock() !== false;
    }

    /**
     * Played = at least one battle. Not "stars > 0": the floors skipped at the start of a period (9 and 10) give
     * their stars without a battle.
     */
    public function isPlayed(): bool
    {
        return ($this->getTotalBattleTimes() ?? 0) > 0;
    }

    public function getStars(): int
    {
        return $this->getTotalStar() ?? 0;
    }

    /**
     * Maximum = 36 stars, all the rewards of the period.
     */
    public function isFullyCleared(): bool
    {
        return $this->getStars() >= self::MAX_STARS;
    }

    /**
     * The floors played below their maximum, in the order of the response. Floors not played are not listed by
     * HoYoLAB, so they are not here either.
     *
     * @return list<SpiralAbyssFloor>
     */
    public function getFloorsBelowMaximum(): array
    {
        return array_values(array_filter($this->getFloors() ?? [], static fn (SpiralAbyssFloor $floor) => !$floor->isFullyCleared()));
    }
}
