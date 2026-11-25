<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Game\HasCheckInStatusInterface;

abstract class GameService implements GameInterface, HasHoyolabCheckInInterface, HasCheckInStatusInterface
{
    use HoyolabTrait;
    use HoyolabCheckInTrait;

}
