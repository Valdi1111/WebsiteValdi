<?php

namespace App\Tests\GamesBundle\Status\Fixtures;

use App\GamesBundle\Model\Game\CheckInStatus;
use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;

/**
 * Game service reporting a fixed check-in status, recording the $fresh flag of each read.
 */
class FakeCheckInGameService implements HasCheckInStatusInterface
{
    /** @var list<bool> */
    public array $reads = [];

    public function __construct(
        public CheckInStatus $status,
    ) {
    }

    public function getCheckInStatus(RuntimeAccountDataInterface $account, bool $fresh = false): CheckInStatus
    {
        $this->reads[] = $fresh;

        return $this->status;
    }
}
