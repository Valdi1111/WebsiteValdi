<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Game\HasNotesInterface;

use App\GamesBundle\Model\GameNotes\GameNotesStateMetric;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasShopStatusInterface extends HasNotesInterface
{
    public function getShopStatusData(RuntimeAccountData $account): GameNotesStateMetric;
}