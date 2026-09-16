<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Triggers the monthly diary synchronization process across all registered game profiles.
 *
 * Typically dispatched on a schedule (e.g., at the start of each month), this message
 * orchestrates the initialization of diary fetching by queuing individual page-level
 * sync tasks for each supported game and currency stream.
 */
#[AsMessage('hoyoverse')]
class SyncDiaryMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{

    public function getFeatureFlagField(): string
    {
        return 'syncDiary';
    }

}