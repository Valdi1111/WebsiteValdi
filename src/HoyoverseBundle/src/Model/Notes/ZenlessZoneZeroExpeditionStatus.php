<?php

namespace App\HoyoverseBundle\Model\Notes;

enum ZenlessZoneZeroExpeditionStatus: string
{
    case ONGOING = 'Ongoing';
    case FINISHED = 'Finished';

    public function isFinished(): bool
    {
        return $this === self::FINISHED;
    }
}