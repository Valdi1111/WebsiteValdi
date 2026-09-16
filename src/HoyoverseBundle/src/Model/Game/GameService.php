<?php

namespace App\HoyoverseBundle\Model\Game;

abstract class GameService implements GameInterface, HasHoyolabCheckInInterface
{
    use HoyolabTrait;
    use HoyolabCheckInTrait;

}