<?php

namespace App\HoyoverseBundle\Model\Notes;

interface HasShopStatusNotes
{
    public function getVhsSaleState(): ?ZenlessZoneZeroVhsSale;
}