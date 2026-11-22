<?php

namespace App\GamesBundle\Model\Pull\Manual;

/**
 * A filler row the realign step wants to exist.
 */
final readonly class FillerSlot
{
    /**
     * @param \DateTimeImmutable $pulledAt   Estimated pull date (keeps the timezone of the neighbour it derives from)
     * @param int                $sId        S that owns the window the filler belongs to
     * @param int|null           $pullNumber Pull number, only for a filler between two numbered rows of one second
     */
    public function __construct(
        public \DateTimeImmutable $pulledAt,
        public int $sId,
        public ?int $pullNumber = null,
    ) {
    }
}
