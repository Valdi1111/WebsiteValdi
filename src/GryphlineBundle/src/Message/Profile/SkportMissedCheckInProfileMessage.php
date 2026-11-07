<?php

namespace App\GryphlineBundle\Message\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Checks if a profile successfully checked in today before daily reset.
 */
#[AsMessage('gryphline')]
class SkportMissedCheckInProfileMessage implements ProfileTaskMessageInterface
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
