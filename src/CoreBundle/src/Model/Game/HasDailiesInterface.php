<?php

namespace App\CoreBundle\Model\Game;

use App\CoreBundle\Model\GameNotes\GameNotesDailies;

interface HasDailiesInterface extends HasNotesInterface
{
    public function getDailiesData(RuntimeAccountDataInterface $account): GameNotesDailies;
}
