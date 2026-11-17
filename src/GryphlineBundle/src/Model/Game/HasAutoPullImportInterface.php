<?php

namespace App\GryphlineBundle\Model\Game;

use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;

interface HasAutoPullImportInterface
{
    /**
     * @return array<string> Categories to pull (e.g. ['char', 'weapon'])
     */
    public function getPullPoolCategories(): array;

    /**
     * Returns banner groups configuration for analytics and pity computation.
     *
     * @return array<string, array{
     *     key: string,
     *     name: string,
     *     categories: array<string>,
     *     s_max_pity: int,
     *     a_max_pity: int
     * }>
     */
    public function getGachaBannerGroups(): array;

    /**
     * Returns rarity tier definitions for this game (top, secondary, base).
     *
     * @return array{
     *     s_rarity: int,
     *     s_label: string,
     *     s_short: string,
     *     a_rarity: int,
     *     a_label: string,
     *     a_short: string,
     *     b_rarity: int,
     *     b_label: string,
     *     b_short: string,
     *     pull_label: string
     * }
     */
    public function getRarityConfig(): array;

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
