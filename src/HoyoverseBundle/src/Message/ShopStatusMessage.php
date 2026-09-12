<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This will check your current shop status and will fire a notification if your shop has finished selling.
 */
#[AsMessage('hoyoverse')]
class ShopStatusMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{

    public function getFeatureFlagField(): string
    {
        return 'shopStatusCheck';
    }

}