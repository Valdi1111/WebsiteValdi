<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Service\Catalog\GiCatalog;
use App\HoyoverseBundle\Service\Catalog\HsrCatalog;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalog;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;

/**
 * Builds the rate-up lookup of a game profile from its game's irminsul.gg catalog, in the timezone of its server.
 */
class HoyoverseRateUpLookupFactory
{
    public function __construct(
        private readonly GiCatalog $giCatalog,
        private readonly HsrCatalog $hsrCatalog,
        private readonly ZzzCatalog $zzzCatalog,
        private readonly HoyoverseServerTimezoneResolver $timezoneResolver,
    ) {
    }

    /**
     * Null for a game without catalog, or while its catalog has not been built (rate-up flags then stay unknown).
     */
    public function forProfile(HoyoverseGameProfile $profile): ?HoyoverseRateUpLookup
    {
        [$catalog, $game] = match ($profile->getGameId()) {
            GenshinImpactService::GAME_ID => [$this->giCatalog, HoyoverseRateUpLookup::GAME_GI],
            HonkaiStarRailService::GAME_ID => [$this->hsrCatalog, HoyoverseRateUpLookup::GAME_HSR],
            ZenlessZoneZeroService::GAME_ID => [$this->zzzCatalog, HoyoverseRateUpLookup::GAME_ZZZ],
            default => [null, null],
        };
        if ($catalog === null || !$catalog->isLoaded()) {
            return null;
        }

        return new HoyoverseRateUpLookup($catalog, $this->timezoneResolver->getServerTimezoneForProfile($profile), $game);
    }
}
