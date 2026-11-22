<?php

namespace App\GamesBundle\Model\Pull\SRankHistory;

/**
 * One S pull of an official S-rank history (e.g. the HoYoLAB "Signal Search" statistics of Zenless Zone Zero).
 *
 * Unlike a recorded pull it has no provider id: it is known by its pull number in the banner.
 */
final readonly class SRankHistoryRecord
{
    /**
     * @param string|null        $itemId     Provider item id (the same as the recorded pulls of the provider)
     * @param string             $itemType   Item type as the provider stores it on its pulls (e.g. "Agents")
     * @param int                $pullNumber Pull number in the banner (1 = first pull of the account on that banner)
     * @param int                $pity       Pulls since the previous S, this one included
     * @param \DateTimeImmutable $pulledAt   Server wall-clock time tagged as UTC, like the recorded pulls of the provider
     * @param bool|null          $isUp       Whether it was the rate-up item (null = the source does not say)
     */
    public function __construct(
        public ?string $itemId,
        public string $name,
        public string $itemType,
        public int $pullNumber,
        public int $pity,
        public \DateTimeImmutable $pulledAt,
        public ?bool $isUp = null,
    ) {
    }
}
