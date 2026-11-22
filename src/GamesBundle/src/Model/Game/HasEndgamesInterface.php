<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\Endgame\GameEndgames;

/**
 * The game exposes the player's progress in its rotating endgame modes, enabling the Endgames Reminder.
 */
interface HasEndgamesInterface
{
    /**
     * One entry per endgame mode of the current period: when the period ends, whether the player has played it,
     * how far from the maximum it is and the score of each stage. Modes the player has not unlocked yet are
     * returned as locked; a mode whose response cannot be read is left out.
     */
    public function getEndgamesData(RuntimeAccountDataInterface $account): GameEndgames;
}
