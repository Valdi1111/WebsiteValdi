<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\Notes\GameNotesDailies;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasDailiesInterface extends HasNotesInterface
{
    public function getDailiesData(RuntimeAccountData $account): GameNotesDailies;
}