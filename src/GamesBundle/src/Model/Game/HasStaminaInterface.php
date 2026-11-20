<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\GameNotes\GameNotesStamina;

interface HasStaminaInterface extends HasNotesInterface
{
    public function getRegenRate(): int;

    public function getMaxStamina(): int;

    public function getStaminaData(RuntimeAccountDataInterface $account): GameNotesStamina;
}
