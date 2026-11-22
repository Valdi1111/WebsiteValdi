<?php

namespace App\GamesBundle\Model\Pull;

/**
 * Where a pull row comes from.
 *
 * - Recorded: imported by a sync, the PowerShell script or a backup import (every pre-existing row).
 * - Manual: an S pull added by the user, always with a declared pity.
 * - Official: an S pull built from an official S-rank history (HoYoLAB Signal Search), with its pity as
 *   declared pity and its pull number in the banner. Counted and merged like a manual S, but its pity and
 *   date are not estimates: the user cannot correct it, delete it or add an S right before it.
 *   A recorded S confirmed by that history stays recorded.
 * - Filler: an "unknown" pull created by the realign step to make the declared pities add up.
 *   Never edited or deleted by hand.
 */
enum PullOrigin: string
{
    case Recorded = 'recorded';
    case Manual = 'manual';
    case Official = 'official';
    case Filler = 'filler';
}
