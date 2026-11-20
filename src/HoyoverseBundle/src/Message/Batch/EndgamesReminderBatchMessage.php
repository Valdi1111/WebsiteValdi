<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\RegionalTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you to complete your endgames.
 */
#[AsMessage('hoyoverse')]
class EndgamesReminderBatchMessage implements RegionalTaskMessageInterface, FeatureFlagMessageInterface
{
    public function __construct(
        private readonly string $timezone
    )
    {
    }

    public function getFeatureFlagField(): string
    {
        return 'endgamesCheck';
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }
}