<?php

namespace App\GryphlineBundle\Model\Game;

use App\GamesBundle\Model\Game\GachaConfig;
use App\GamesBundle\Model\Game\RarityConfig;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;

interface HasAutoPullImportInterface
{
    public function getGachaConfig(): GachaConfig;

    public function getRarityConfig(): RarityConfig;

    /**
     * Pool type of a sync category: a banner key, or a legacy alias such as "char".
     */
    public function getPoolTypeForCategory(string $category): string;

    /**
     * Pool category (record endpoint, stored in gryphline_pull.pool_category) of a pool type.
     */
    public function getPoolCategoryForPoolType(string $poolType): string;

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
