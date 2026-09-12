<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Check for your stamina and notify you when it's within the set threshold.
 */
#[AsMessage('hoyoverse')]
class StaminaCheckMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{

    public function getFeatureFlagField(): string
    {
        return 'staminaCheck';
    }

}