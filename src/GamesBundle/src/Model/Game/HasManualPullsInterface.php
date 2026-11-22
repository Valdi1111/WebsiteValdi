<?php

namespace App\GamesBundle\Model\Game;

/**
 * Marker capability: the game's pull trackers accept missing pulls added by the user
 * (manual S pulls and declared pities, completed by filler rows).
 *
 * Implemented by the provider game services; their profile normalizers expose it to the
 * frontend as the "supports_manual_pulls" flag.
 */
interface HasManualPullsInterface
{
}
