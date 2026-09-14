<?php

namespace App\HoyoverseBundle\Model\Notes;

use App\CoreBundle\Model\LabeledInterface;

enum ZenlessZoneZeroVhsSale: string implements LabeledInterface
{
    case NO = 'SaleStateNo';
    case DOING = 'SaleStateDoing';
    case DONE = 'SaleStateDone';

    public function isDone(): bool
    {
        return $this === self::DONE;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::NO => 'Closed',
            self::DOING => 'Open',
            self::DONE => 'Finished',
        };
    }
}