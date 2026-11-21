<?php

namespace App\PerfectworldBundle\Model\Game;

use App\GamesBundle\Model\Game\GachaConfig;
use App\GamesBundle\Model\Game\RarityConfig;

/**
 * The game has a pull tracker fed by manual imports.
 */
interface HasPullTrackerInterface
{
    public function getGachaConfig(): GachaConfig;

    public function getRarityConfig(): RarityConfig;

    /**
     * Unit counted by a banner's pity, plural and singular (e.g. "Rolls"/"Roll", "Arcs"/"Arc").
     *
     * @return array{plural: string, singular: string}
     */
    public function getBannerPullLabels(string $bannerKey): array;
}
