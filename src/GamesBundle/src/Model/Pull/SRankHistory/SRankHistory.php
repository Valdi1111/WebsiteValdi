<?php

namespace App\GamesBundle\Model\Pull\SRankHistory;

/**
 * The official S-rank history of a game profile: one entry per banner group the source knows.
 */
final readonly class SRankHistory
{
    /**
     * @param list<SRankHistoryBanner> $banners
     * @param list<string>             $warnings What the source returned and could not be used (e.g. an unknown banner type)
     */
    public function __construct(
        public array $banners,
        public array $warnings = [],
    ) {
    }

    public function getBanner(string $bannerKey): ?SRankHistoryBanner
    {
        foreach ($this->banners as $banner) {
            if ($banner->bannerKey === $bannerKey) {
                return $banner;
            }
        }

        return null;
    }
}
