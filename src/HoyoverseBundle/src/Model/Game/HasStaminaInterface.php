<?php

namespace App\HoyoverseBundle\Model\Game;

interface HasStaminaInterface extends HasNotesInterface
{
    public function getRegenRate(): int;

    public function getMaxStamina(): int;
}