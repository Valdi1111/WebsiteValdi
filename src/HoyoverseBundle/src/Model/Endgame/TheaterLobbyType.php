<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One period of the Imaginarium Theater (role_combat data[]): the current one or the previous one, told apart by
 * schedule.schedule_type.
 */
class TheaterLobbyType
{
    #[SerializedName('schedule')]
    private ?ScheduleData $schedule = null;

    #[SerializedName('stat')]
    private ?TheaterStat $stat = null;

    #[SerializedName('has_data')]
    private ?bool $hasData = null;

    #[SerializedName('has_detail_data')]
    private ?bool $hasDetailData = null;

    public function getSchedule(): ?ScheduleData
    {
        return $this->schedule;
    }

    public function setSchedule(?ScheduleData $schedule): static
    {
        $this->schedule = $schedule;
        return $this;
    }

    public function getStat(): ?TheaterStat
    {
        return $this->stat;
    }

    public function setStat(?TheaterStat $stat): static
    {
        $this->stat = $stat;
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

    public function getHasDetailData(): ?bool
    {
        return $this->hasDetailData;
    }

    public function setHasDetailData(?bool $hasDetailData): static
    {
        $this->hasDetailData = $hasDetailData;
        return $this;
    }

    public function isCurrent(): bool
    {
        return $this->getSchedule()?->getScheduleType() === ScheduleData::SCHEDULE_TYPE_CURRENT;
    }

    /**
     * Played = a record in the period (has_data). Assumed to include a run not finished yet: to be checked in the
     * December 2026 period.
     */
    public function isPlayed(): bool
    {
        return $this->getHasData() === true;
    }
}
