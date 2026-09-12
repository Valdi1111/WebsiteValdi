<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Check for ongoing expeditions every 30 minutes and send a notification if all expeditions are completed.
 */
#[AsMessage('hoyoverse')]
class ExpeditionCheckMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{

    public function getFeatureFlagField(): string
    {
        return 'expeditionCheck';
    }

}