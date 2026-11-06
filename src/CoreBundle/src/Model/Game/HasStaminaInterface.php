<?php

namespace App\CoreBundle\Model\Game;

use App\CoreBundle\Model\GameNotes\GameNotesStamina;

interface HasStaminaInterface extends HasNotesInterface
{
    public function getRegenRate(): int;

    public function getMaxStamina(): int;

    public function getStaminaData(RuntimeAccountDataInterface $account): GameNotesStamina;
}
