<?php

namespace App\GamesBundle\Model\Pull\Card;

/**
 * One banner on the shareable card: its totals and its S pulls, newest first.
 */
final readonly class PullCardSection
{
    /**
     * @param list<PullCardItem> $items       the S pulls shown, newest first
     * @param int                $hiddenCount older S pulls left out of the card
     * @param int                $upCount     S pulls that were the rate-up, among all of them
     * @param int                $upKnown     S pulls whose rate-up flag is known, among all of them
     * @param ?string            $pullLabel   unit of the banner when it has its own (NTE "Arcs"), else the game's
     */
    public function __construct(
        public string $name,
        public int $totalPulls,
        public int $sCount,
        public float $avgSPity,
        public int $sPity,
        public int $sMaxPity,
        public array $items,
        public int $hiddenCount = 0,
        public int $upCount = 0,
        public int $upKnown = 0,
        public ?string $pullLabel = null,
    ) {
    }
}
