<?php

namespace App\HoyoverseBundle\Model\Game;

use App\CoreBundle\Model\Game\GachaConfig;
use App\CoreBundle\Model\Game\RarityConfig;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;

interface HasPullTrackerInterface
{
    public function getGachaConfig(): GachaConfig;

    public function getRarityConfig(): RarityConfig;

    /**
     * Fetches a single page of pulls for the given gacha_type and optional end_id cursor.
     *
     * @param array<string, mixed> $extraQueryParams
     *
     * @return array<HoyoversePull>
     */
    public function fetchPullsPage(
        HoyoverseGameProfile $profile,
        string|int $gachaType,
        ?string $authkey = null,
        ?string $endId = '0',
        array $extraQueryParams = [],
        int $size = 20
    ): array;
}
