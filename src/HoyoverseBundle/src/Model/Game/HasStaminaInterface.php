<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\Notes\GameNotesStamina;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasStaminaInterface extends HasNotesInterface
{
    public function getRegenRate(): int;

    public function getMaxStamina(): int;

    public function getStaminaData(RuntimeAccountData $account): GameNotesStamina;
}