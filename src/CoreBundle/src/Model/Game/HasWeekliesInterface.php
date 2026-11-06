<?php

namespace App\CoreBundle\Model\Game;

use App\CoreBundle\Model\GameNotes\GameNotesWeeklies;

interface HasWeekliesInterface extends HasNotesInterface
{
    public function getWeekliesData(RuntimeAccountDataInterface $account): GameNotesWeeklies;
}
