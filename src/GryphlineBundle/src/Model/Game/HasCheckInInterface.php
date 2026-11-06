<?php

namespace App\GryphlineBundle\Model\Game;

use App\CoreBundle\Model\Game\CheckInResult;
use App\GryphlineBundle\Model\RuntimeAccountData;

interface HasCheckInInterface
{
    public function checkIn(RuntimeAccountData $account): CheckInResult;
}
