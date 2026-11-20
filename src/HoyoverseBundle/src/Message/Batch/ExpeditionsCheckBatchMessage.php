<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Check for ongoing expeditions every 30 minutes and send a notification if all expeditions are completed.
 */
#[AsMessage('hoyoverse')]
class ExpeditionsCheckBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'expeditionsCheck';
    }
}