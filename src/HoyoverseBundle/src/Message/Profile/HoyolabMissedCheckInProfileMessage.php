<?php

namespace App\HoyoverseBundle\Message\Profile;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This check if all accounts successfully checked in today before daily reset.
 */
#[AsMessage('hoyoverse')]
class HoyolabMissedCheckInProfileMessage implements ProfileTaskMessageInterface
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