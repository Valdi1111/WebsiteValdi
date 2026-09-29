<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\HoyoverseBundle\Message\FeatureFlagMessageInterface;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Triggers the monthly diary synchronization process across all registered game profiles.
 *
 * Typically dispatched on a schedule (e.g., at the start of each month), this message
 * orchestrates the initialization of diary fetching by queuing individual page-level
 * sync tasks for each supported game and currency stream.
 */
#[AsMessage('hoyoverse')]
class SyncDiaryBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    /**
     * @param int|null $year  Four-digit year (e.g., 2026). If null, defaults to the previous month's year.
     * @param int|null $month Month from 1 to 12. If null, defaults to the previous month.
     */
    public function __construct(
        public readonly ?int $year = null,
        public readonly ?int $month = null,
    ) {
        if ($this->month !== null && ($this->month < 1 || $this->month > 12)) {
            throw new \InvalidArgumentException(sprintf('The month must be between 1 and 12, received: %d', $this->month));
        }

        if (($this->year === null && $this->month !== null) || ($this->year !== null && $this->month === null)) {
            throw new \InvalidArgumentException('You must specify both year and month, or leave both null.');
        }
    }

    public function getFeatureFlagField(): string
    {
        return 'syncDiary';
    }

    /**
     * Resolves the target date, setting it to the first day of the specified month
     * or falling back to the first day of last month.
     */
    public function resolveTargetDate(): \DateTimeImmutable
    {
        if ($this->year !== null && $this->month !== null) {
            return new \DateTimeImmutable()
                ->setDate($this->year, $this->month, 1)
                ->setTime(0, 0, 0);
        }

        return new \DateTimeImmutable('first day of last month')->setTime(0, 0, 0);
    }
}