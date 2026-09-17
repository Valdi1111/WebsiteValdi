<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasRealmInterface extends HasNotesInterface
{
    public function getRealmData(RuntimeAccountData $account): GameNotesRealm;
}