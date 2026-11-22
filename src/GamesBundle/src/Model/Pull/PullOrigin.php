<?php

namespace App\GamesBundle\Model\Pull;

/**
 * Where a pull row comes from.
 *
 * - Recorded: imported by a sync, the PowerShell script or a backup import (every pre-existing row).
 * - Manual: an S pull added by the user, always with a declared pity.
 * - Filler: an "unknown" pull created by the realign step to make the declared pities add up.
 *   Never edited or deleted by hand.
 */
enum PullOrigin: string
{
    case Recorded = 'recorded';
    case Manual = 'manual';
    case Filler = 'filler';
}
