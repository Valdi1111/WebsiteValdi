<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Run daily check-in every day at midnight or your specified time
 */
#[AsMessage('hoyoverse')]
class HoyolabCheckInMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{

    public function getFeatureFlagField(): string
    {
        return 'hoyolabCheckIn';
    }

}