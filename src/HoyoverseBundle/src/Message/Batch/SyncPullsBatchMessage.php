<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Triggers the scheduled gacha/pulls history synchronization process across all eligible game profiles.
 *
 * Orchestrates the initialization of pull fetching by queuing individual page-level
 * sync tasks for each supported game and banner stream with staggering delays.
 */
#[AsMessage('hoyoverse')]
class SyncPullsBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'syncPulls';
    }
}
