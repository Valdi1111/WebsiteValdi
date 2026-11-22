<?php

namespace App\GamesBundle\Model\Pull\Manual;

/**
 * Changes that bring one banner group to its expected state (see PullGapPlanner::plan()).
 *
 * Apply in this order: merges (set the declared pity updates, delete the merged manual rows),
 * then filler moves, deletes and creates.
 */
final readonly class FillerPlan
{
    /**
     * @param array<int, int>                $merges              Manual S id => recorded S id that replaces it (delete the manual row)
     * @param array<int, int>                $declaredPityUpdates Recorded S id => declared pity inherited from a merged manual S
     * @param array<int, \DateTimeImmutable> $fillerMoves         Existing filler id => new pull date
     * @param list<int>                      $fillerDeletes       Existing filler ids to delete
     * @param list<FillerSlot>               $fillerCreates       Filler rows to create
     * @param list<string>                   $warnings            Declarations that could not be satisfied
     */
    public function __construct(
        public array $merges = [],
        public array $declaredPityUpdates = [],
        public array $fillerMoves = [],
        public array $fillerDeletes = [],
        public array $fillerCreates = [],
        public array $warnings = [],
    ) {
    }

    /**
     * Whether the plan changes nothing (warnings are ignored).
     */
    public function isEmpty(): bool
    {
        return $this->merges === []
            && $this->declaredPityUpdates === []
            && $this->fillerMoves === []
            && $this->fillerDeletes === []
            && $this->fillerCreates === [];
    }
}
