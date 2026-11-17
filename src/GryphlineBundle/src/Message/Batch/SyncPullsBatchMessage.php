<?php

namespace App\GryphlineBundle\Message\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Triggers the scheduled gacha/pulls history synchronization process across all eligible Gryphline game profiles.
 *
 * Orchestrates the initialization of pull fetching by queuing individual page-level
 * sync tasks for each supported game and pool category stream with staggering delays.
 */
#[AsMessage('gryphline')]
class SyncPullsBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'syncPulls';
    }
}
