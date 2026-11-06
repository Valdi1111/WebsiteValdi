<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\RegionalTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you to complete your weeklies.
 */
#[AsMessage('hoyoverse')]
class WeekliesReminderBatchMessage implements RegionalTaskMessageInterface, FeatureFlagMessageInterface
{
    public function __construct(
        private readonly string $timezone
    )
    {
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