<?php

namespace App\HoyoverseBundle\Model\Notes;

interface HasHowlScratchCardNotes
{
    public function getCardSign(): ?ZenlessZoneZeroCardSignStatus;
}