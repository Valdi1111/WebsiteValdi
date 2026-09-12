<?php

namespace App\HoyoverseBundle\Model\Notes;

enum ZenlessZoneZeroVhsSaleState: string
{
    case NO = 'SaleStateNo';
    case DOING = 'SaleStateDoing';
    case DONE = 'SaleStateDone';

    public function getLabel(): string
    {
        return match ($this) {
            self::NO => 'Closed',
            self::DOING => 'Open',
            self::DONE => 'Finished',
        };
    }
}