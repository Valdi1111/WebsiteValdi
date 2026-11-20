<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;

interface HasWeekliesInterface extends HasNotesInterface
{
    public function getWeekliesData(RuntimeAccountDataInterface $account): GameNotesWeeklies;
}
