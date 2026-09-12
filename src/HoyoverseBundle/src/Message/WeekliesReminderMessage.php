<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you to complete your weeklies.
 */
#[AsMessage('hoyoverse')]
class WeekliesReminderMessage implements RegionalTaskMessageInterface, FeatureFlagMessageInterface
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