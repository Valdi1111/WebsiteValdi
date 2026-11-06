<?php

namespace App\GryphlineBundle\Message\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Check stamina threshold for a profile
 */
#[AsMessage('gryphline')]
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
