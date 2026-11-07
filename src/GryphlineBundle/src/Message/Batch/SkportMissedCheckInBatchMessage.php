<?php

namespace App\GryphlineBundle\Message\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\TaskMessageInterface;
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
