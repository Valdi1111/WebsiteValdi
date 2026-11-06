<?php

namespace App\HoyoverseBundle\Model\Game;

use App\CoreBundle\Model\Game\HasNotesInterface;

use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasRealmCurrencyInterface extends HasNotesInterface
{
    public function getRealmData(RuntimeAccountData $account): GameNotesRealm;
}