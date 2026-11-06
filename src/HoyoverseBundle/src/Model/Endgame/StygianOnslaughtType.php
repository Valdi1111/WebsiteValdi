<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

class StygianOnslaughtType
{
    #[SerializedName('schedule')]
    private ?ScheduleData $schedule = null;

    public function getSchedule(): ?ScheduleData
    {
        return $this->schedule;
    }

    public function setSchedule(?ScheduleData $schedule): static
    {
        $this->schedule = $schedule;
        return $this;
    }
}
