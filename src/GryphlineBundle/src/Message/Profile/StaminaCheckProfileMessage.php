<?php

namespace App\GryphlineBundle\Message\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;

class StaminaCheckProfileMessage implements ProfileTaskMessageInterface
{
    public function __construct(
        private readonly int $gameProfileId
    ) {
    }

    public function getGameProfileId(): int
    {
        return $this->gameProfileId;
    }
}
