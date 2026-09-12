<?php

namespace App\HoyoverseBundle\Model\Notes;

enum ZenlessZoneZeroCardSignStatus: string
{
    case NO = 'CardSignNo';
    case DONE = 'CardSignDone';

    public function isDone(): bool
    {
        return $this === self::DONE;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::NO => 'Not Completed',
            self::DONE => 'Completed',
        };
    }
}