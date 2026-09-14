<?php

namespace App\HoyoverseBundle\Model\Notes;

use App\CoreBundle\Model\LabeledInterface;

enum ZenlessZoneZeroCafe: string implements LabeledInterface
{
    case NO = 'CafeStateNo';
    case DONE = 'CafeStateDone';

    public function isDone(): bool
    {
        return $this === self::DONE;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::NO => 'To Drink',
            self::DONE => 'Drank',
        };
    }
}