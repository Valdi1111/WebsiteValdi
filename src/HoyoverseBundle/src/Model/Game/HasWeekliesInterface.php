<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\Notes\GameNotesWeeklies;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasWeekliesInterface extends HasNotesInterface
{
    public function getWeekliesData(RuntimeAccountData $account): GameNotesWeeklies;
}