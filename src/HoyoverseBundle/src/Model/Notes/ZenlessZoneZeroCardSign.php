<?php

namespace App\HoyoverseBundle\Model\Notes;

use App\CoreBundle\Model\LabeledInterface;

enum ZenlessZoneZeroCardSign: string implements LabeledInterface
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