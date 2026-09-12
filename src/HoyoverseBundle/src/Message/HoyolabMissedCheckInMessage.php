<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This check if all accounts successfully checked in today before daily reset.
 */
#[AsMessage('hoyoverse')]
class HoyolabMissedCheckInMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{

    public function getFeatureFlagField(): string
    {
        return 'hoyolabMissedCheckIn';
    }

}