<?php

namespace App\HoyoverseBundle\Model\Game;

use App\CoreBundle\Model\Game\HasNotesInterface;

use App\CoreBundle\Model\GameNotes\GameNotesStateMetric;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasShopStatusInterface extends HasNotesInterface
{
    public function getShopStatusData(RuntimeAccountData $account): GameNotesStateMetric;
}