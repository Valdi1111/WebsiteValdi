<?php

namespace App\GamesBundle\Model\Pull\SRankHistory;

/**
 * What an S-rank history import must write on one banner group (see SRankHistoryReconciler::reconcile()).
 *
 * When $conflicts is not empty the banner must be left as it is: nothing is written.
 */
final readonly class SRankHistoryBannerPlan
{
    /**
     * @param list<SRankHistoryRecord>    $records    Records of the banner after the chain checks, by pull number
     * @param list<string>                $conflicts  Why the banner cannot be imported (empty: it can)
     * @param list<SRankHistoryRecord>    $creates    Official S rows to create
     * @param list<SRankHistoryRowUpdate> $updates    Values of the stored S matched with a record (also when unchanged)
     * @param list<string>                $overwrites Values entered by the user that the official history replaces
     * @param list<string>                $warnings   Differences that do not stop the import
     * @param bool                        $realignNeeded Whether the realign changes the fillers once these values are written;
     *                                                   true even without any S change when the stored fillers are out of
     *                                                   line (e.g. a sync that stopped before its last page)
     */
    public function __construct(
        public array $records = [],
        public array $conflicts = [],
        public array $creates = [],
        public array $updates = [],
        public array $overwrites = [],
        public array $warnings = [],
        public bool $realignNeeded = false,
    ) {
    }

    public function isRefused(): bool
    {
        return $this->conflicts !== [];
    }
}
