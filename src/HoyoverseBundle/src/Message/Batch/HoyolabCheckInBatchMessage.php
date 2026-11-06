<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Run daily check-in every day at midnight or your specified time
 */
#[AsMessage('hoyoverse')]
class HoyolabCheckInBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'hoyolabCheckIn';
    }
}