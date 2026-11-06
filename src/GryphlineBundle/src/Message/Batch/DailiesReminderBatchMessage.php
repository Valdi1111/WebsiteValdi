<?php

namespace App\GryphlineBundle\Message\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\RegionalTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you to complete your dailies.
 */
#[AsMessage('gryphline')]
class DailiesReminderBatchMessage implements RegionalTaskMessageInterface, FeatureFlagMessageInterface
{
    public function __construct(
        private readonly string $timezone
    ) {
    }

    public function getFeatureFlagField(): string
    {
        return 'dailiesCheck';
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }
}
