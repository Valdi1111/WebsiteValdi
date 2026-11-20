<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Check for your stamina and notify you when it's within the set threshold.
 */
#[AsMessage('hoyoverse')]
class StaminaCheckBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'staminaCheck';
    }
}