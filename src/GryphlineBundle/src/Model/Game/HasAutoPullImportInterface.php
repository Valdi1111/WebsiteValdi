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
     * Sync streams (one paginated read of the record API each) and their display names. A stream may feed several
     * banner groups (see getSyncStreamBanners()); its key is the sync category of the messages.
     *
     * @return array<string, string> stream key => display name
     */
    public function getSyncStreams(): array;

    /**
     * Banner group keys a sync stream stores pulls into (realigned when the stream ends).
     *
     * @return list<string>
     */
    public function getSyncStreamBanners(string $stream): array;

    /**
     * Whether the pity of a banner group resets with each banner (pool id) instead of carrying over.
     */
    public function isPoolPityBanner(string $bannerKey): bool;

    /**
     * Whether a sync stream reads its records banner by banner (pool id required by the record API).
     */
    public function requiresPoolId(string $stream): bool;

    /**
     * Pool ids a stream must read one after another (empty when the stream needs none or has none right now).
     *
     * @return list<string>
     */
    public function fetchStreamPoolIds(GryphlineGameProfile $profile, string $stream, string $u8Token): array;

    /**
     * Pool type of a sync category: a banner key, or a legacy alias such as "char".
     */
    public function getPoolTypeForCategory(string $category): string;

    /**
     * Pool category (record endpoint, stored in gryphline_pull.pool_category) of a pool type.
     */
    public function getPoolCategoryForPoolType(string $poolType): string;

    /**
     * Fetches a single page of records (pulls and bonus entries) for a sync category and optional seq_id cursor.
     *
     * @return array<GryphlinePull>
     */
    public function fetchPullsPage(
        GryphlineGameProfile $profile,
        string $category,
        string $u8Token,
        ?string $seqId = null,
        ?string &$nextSeqId = null,
        ?string $poolId = null,
    ): array;
}
