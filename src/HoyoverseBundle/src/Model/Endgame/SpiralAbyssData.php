<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

class SpiralAbyssData extends ScheduleData
{
    #[SerializedName('max_floor')]
    private ?string $maxFloor = null;

    #[SerializedName('total_start')]
    private ?int $totalStar = null;

    #[SerializedName('is_unlock')]
    private ?bool $isUnlock = null;

    #[SerializedName('is_just_skipped_floor')]
    private ?bool $isJustSkippedFloor = null;

    #[SerializedName('skipped_floor')]
    private ?string $skippedFloor = null;

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

}
