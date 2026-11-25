<?php

namespace App\GamesBundle\Model\Game;

/**
 * The game reports whether today's daily check-in was claimed, through a read-only call.
 */
interface HasCheckInStatusInterface
{
    /**
     * Today's check-in, read from the per-profile cache when a recent copy is there. It never claims the reward.
     *
     * @param bool $fresh skip the cached copy: call the API and store its answer
     */
    public function getCheckInStatus(RuntimeAccountDataInterface $account, bool $fresh = false): CheckInStatus;
}
