<?php

namespace App\HoyoverseBundle\Message\Profile;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you to complete your weeklies.
 */
#[AsMessage('hoyoverse')]
class WeekliesReminderProfileMessage implements ProfileTaskMessageInterface
{
    public function __construct(
        private readonly int $gameProfileId
    )
    {
    }

    public function getGameProfileId(): int
    {
        return $this->gameProfileId;
    }
}