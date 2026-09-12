<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you to complete your dailies.
 */
#[AsMessage('hoyoverse')]
class DailiesReminderMessage implements RegionalTaskMessageInterface, FeatureFlagMessageInterface
{

    public function __construct(
        private readonly string $timezone
    )
    {
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