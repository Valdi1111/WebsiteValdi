<?php

namespace App\GryphlineBundle\Message\Batch;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Checks if all accounts successfully checked in today before daily reset.
 */
#[AsMessage('gryphline')]
class SkportMissedCheckInBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'skportMissedCheckIn';
    }
}
