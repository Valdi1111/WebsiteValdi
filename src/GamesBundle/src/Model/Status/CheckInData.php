<?php

namespace App\GamesBundle\Model\Status;

use App\GamesBundle\Model\Game\CheckInStatus;

/**
 * Data of the check-in section: today's check-in and whether the site claims it for the user (auto check-in).
 */
final readonly class CheckInData implements FetchedDataInterface
{
    public function __construct(
        public CheckInStatus $status,
        public bool $autoCheckIn,
    ) {
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->status->fetchedAt;
    }
}
