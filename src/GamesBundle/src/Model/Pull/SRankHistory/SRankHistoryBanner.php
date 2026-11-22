<?php

namespace App\GamesBundle\Model\Pull\SRankHistory;

/**
 * The official S-rank history of one banner group, with the banner totals of the same source.
 */
final readonly class SRankHistoryBanner
{
    /**
     * @param string                   $bannerKey   Banner group key of the game's GachaConfig
     * @param list<SRankHistoryRecord> $records     Every S of the banner, in any order
     * @param int|null                 $totalPulls  Pulls of the banner counted by the source (null = unknown)
     * @param int|null                 $sCount      S counted by the source (null = unknown)
     * @param int|null                 $currentPity Pulls after the last S (null = unknown); informative only
     */
    public function __construct(
        public string $bannerKey,
        public array $records,
        public ?int $totalPulls = null,
        public ?int $sCount = null,
        public ?int $currentPity = null,
    ) {
    }
}
