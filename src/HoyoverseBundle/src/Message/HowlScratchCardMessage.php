<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you if you haven't scratched the card at Howl's News Stand.
 */
#[AsMessage('hoyoverse')]
class HowlScratchCardMessage implements RegionalTaskMessageInterface, FeatureFlagMessageInterface
{

    public function __construct(
        private readonly string $timezone
    )
    {
    }

    public function getFeatureFlagField(): string
    {
        return 'howlScratchCardCheck';
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

}