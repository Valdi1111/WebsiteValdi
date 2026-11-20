<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\GameNotes\GameNotesDailies;

interface HasDailiesInterface extends HasNotesInterface
{
    public function getDailiesData(RuntimeAccountDataInterface $account): GameNotesDailies;
}
