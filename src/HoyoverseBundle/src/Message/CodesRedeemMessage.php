<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Check and redeem codes for supported games from Hoyolab.
 */
#[AsMessage('hoyoverse')]
class CodesRedeemMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{

    public function getFeatureFlagField(): string
    {
        return 'codeRedeem';
    }

}