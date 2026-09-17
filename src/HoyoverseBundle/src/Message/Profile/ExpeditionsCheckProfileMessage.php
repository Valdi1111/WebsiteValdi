<?php

namespace App\HoyoverseBundle\Message\Profile;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Check for ongoing expeditions every 30 minutes and send a notification if all expeditions are completed.
 */
#[AsMessage('hoyoverse')]
class ExpeditionsCheckProfileMessage implements ProfileTaskMessageInterface
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