<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\Status\FetchedDataInterface;

/**
 * Daily check-in of a game profile as the provider reports it, read without claiming anything.
 */
final readonly class CheckInStatus implements FetchedDataInterface
{
    /**
     * @param int|null $totalDays days checked in this month (this period on SKPort), when known
     */
    public function __construct(
        public bool $signedToday,
        public ?int $totalDays = null,
        public ?\DateTimeImmutable $fetchedAt = null,
    ) {
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }
}
