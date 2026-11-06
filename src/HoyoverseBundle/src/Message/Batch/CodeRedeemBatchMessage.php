<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Check and redeem codes for supported games from Hoyolab.
 */
#[AsMessage('hoyoverse')]
class CodeRedeemBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'codeRedeem';
    }
}