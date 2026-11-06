<?php

namespace App\GryphlineBundle\Message\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Run daily Skport check-in for a profile
 */
#[AsMessage('gryphline')]
class SkportCheckInProfileMessage implements ProfileTaskMessageInterface
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
