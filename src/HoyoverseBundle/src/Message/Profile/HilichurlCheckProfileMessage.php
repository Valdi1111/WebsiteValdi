<?php

namespace App\HoyoverseBundle\Message\Profile;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This will run the Hilichurl Machine Workshop automation for Genshin Impact - completing tasks, claiming rewards, and exchanging for Primogems.
 */
#[AsMessage('hoyoverse')]
class HilichurlCheckProfileMessage implements ProfileTaskMessageInterface
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