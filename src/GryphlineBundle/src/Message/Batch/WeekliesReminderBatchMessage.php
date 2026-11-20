<?php

namespace App\GryphlineBundle\Message\Batch;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\RegionalTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you to complete your weeklies.
 */
#[AsMessage('gryphline')]
class WeekliesReminderBatchMessage implements RegionalTaskMessageInterface, FeatureFlagMessageInterface
{
    public function __construct(
        private readonly string $timezone
    ) {
    }

    public function getFeatureFlagField(): string
    {
        return 'weekliesCheck';
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }
}
