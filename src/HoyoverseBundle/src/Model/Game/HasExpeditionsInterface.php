<?php

namespace App\HoyoverseBundle\Model\Game;

use App\CoreBundle\Model\Game\HasNotesInterface;

use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasExpeditionsInterface extends HasNotesInterface
{
    public function getExpeditionsData(RuntimeAccountData $account): GameNotesExpeditions;
}