<?php

namespace App\GamesBundle\Model\Pull\SRankHistory;

/**
 * What an S-rank history import writes on a stored S matched with one of its records.
 */
final readonly class SRankHistoryRowUpdate
{
    /**
     * @param int      $id           Provider row id of the stored S
     * @param bool     $official     True for a manual or official S: it becomes (or stays) an official S with the date,
     *                               pull number, item and rate-up flag of the record. False for a recorded S: it keeps its
     *                               own date and item, and only takes the rate-up flag and the declared pity
     * @param int|null $declaredPity Declared pity to store (null: nothing is missing before a recorded S)
     */
    public function __construct(
        public int $id,
        public SRankHistoryRecord $record,
        public bool $official,
        public ?int $declaredPity,
    ) {
    }
}
