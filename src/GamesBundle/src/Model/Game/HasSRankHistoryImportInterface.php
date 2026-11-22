<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\Pull\SRankHistory\SRankHistory;

/**
 * The game exposes an official history of the S pulls of every banner (pull number, pity, date, rate-up flag), older
 * than the history of its pull logs. The provider imports it on demand into the missing pulls of the pull tracker:
 * official S rows (PullOrigin::Official) and the filler rows between them.
 *
 * Only useful for games that also accept missing pulls (HasManualPullsInterface): the import relies on the same rows.
 */
interface HasSRankHistoryImportInterface
{
    /**
     * Reads the whole official S-rank history of the profile. Throws when a request fails or a response cannot be
     * read, so that an import never works on a partial history.
     */
    public function getSRankHistory(RuntimeAccountDataInterface $account): SRankHistory;
}
