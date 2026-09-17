<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\HoyoverseBundle\Message\FeatureFlagMessageInterface;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This will check your current shop status and will fire a notification if your shop has finished selling.
 */
#[AsMessage('hoyoverse')]
class ShopStatusCheckBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'shopStatusCheck';
    }
}