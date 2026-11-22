<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * What the Endgames Reminder does for one endgame mode (or one variant of it, e.g. Deadly Assault Hard) of a profile.
 */
enum EndgameReminder: string
{
    /** No reminder */
    case Off = 'off';

    /** Reminder while the mode has not been played in the current period */
    case NotPlayed = 'not_played';

    /** Reminder while the mode is not at the maximum (not played, or played below the maximum) */
    case FullClear = 'full_clear';
}
