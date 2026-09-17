<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\Notes\GameNotesStateMetric;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasShopStatusInterface extends HasNotesInterface
{
    public function getShopStatusData(RuntimeAccountData $account): GameNotesStateMetric;
}