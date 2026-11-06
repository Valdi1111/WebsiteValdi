<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

class ScheduleData
{
    #[SerializedName('schedule_id')]
    private int|string|null $scheduleId = null;

    #[SerializedName('start_time')]
    #[Context([
        DateTimeNormalizer::FORMAT_KEY => 'U',
        DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
    ])]
    private ?\DateTimeImmutable $startTime = null;

    #[SerializedName('end_time')]
    #[Context([
        DateTimeNormalizer::FORMAT_KEY => 'U',
        DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
    ])]
    private ?\DateTimeImmutable $endTime = null;

    public function getScheduleId(): int|string|null
    {
        return $this->scheduleId;
    }

    public function setScheduleId(int|string|null $scheduleId): static
    {
        $this->scheduleId = $scheduleId;
        return $this;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;
        return $this;
    }

}
