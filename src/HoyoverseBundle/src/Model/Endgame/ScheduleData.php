<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

/**
 * Period of a Genshin Impact endgame record: start and end as epochs (strings in the responses), read as UTC instants.
 */
class ScheduleData
{
    /** schedule_type of the current period (2 = the previous one), where the response has it (Imaginarium Theater) */
    public const int SCHEDULE_TYPE_CURRENT = 1;

    #[SerializedName('schedule_id')]
    private int|string|null $scheduleId = null;

    #[SerializedName('schedule_type')]
    private ?int $scheduleType = null;

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

    public function getScheduleType(): ?int
    {
        return $this->scheduleType;
    }

    public function setScheduleType(?int $scheduleType): static
    {
        $this->scheduleType = $scheduleType;
        return $this;
    }

    /**
     * Whether $now is inside the period: from the start (included) to the end (excluded). False when either is unknown.
     */
    public function contains(\DateTimeInterface $now): bool
    {
        if ($this->getStartTime() === null || $this->getEndTime() === null) {
            return false;
        }

        return $this->getStartTime()->getTimestamp() <= $now->getTimestamp() && $now->getTimestamp() < $this->getEndTime()->getTimestamp();
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
