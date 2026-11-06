<?php

namespace App\GryphlineBundle\Message\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reminds you to complete your weeklies.
 */
#[AsMessage('gryphline')]
class WeekliesReminderProfileMessage implements ProfileTaskMessageInterface
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
