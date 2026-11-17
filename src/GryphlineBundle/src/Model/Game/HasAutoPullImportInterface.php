<?php

namespace App\GryphlineBundle\Model\Game;

use App\CoreBundle\Model\Game\GachaConfig;
use App\CoreBundle\Model\Game\RarityConfig;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;

interface HasAutoPullImportInterface
{
    public function getGachaConfig(): GachaConfig;

    public function getRarityConfig(): RarityConfig;

    /**
     * Fetches a single page of pulls for a category and optional seq_id cursor.
     *
     * @return array<GryphlinePull>
     */
    public function fetchPullsPage(
        GryphlineGameProfile $profile,
        string $category,
        string $u8Token,
        ?string $seqId = null,
        ?string &$nextSeqId = null
    ): array;
}
