<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

class TheaterLobbyType
{
    #[SerializedName('schedule')]
    private ?ScheduleData $schedule = null;

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
}
