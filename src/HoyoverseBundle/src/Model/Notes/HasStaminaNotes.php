<?php

namespace App\HoyoverseBundle\Model\Notes;

interface HasStaminaNotes
{
    public function getStaminaData(): GameNotesStamina;
}