<?php

namespace App\GryphlineBundle\Message\Batch;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Run daily Skport check-in
 */
#[AsMessage('gryphline')]
class SkportCheckInBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'skportCheckIn';
    }
}
