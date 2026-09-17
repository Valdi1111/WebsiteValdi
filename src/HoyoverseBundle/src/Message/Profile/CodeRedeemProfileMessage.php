<?php

namespace App\HoyoverseBundle\Message\Profile;

use App\HoyoverseBundle\Model\RedeemableCode;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Redeem a single code for a game profile.
 */
#[AsMessage('hoyoverse_redeem')]
class CodeRedeemProfileMessage implements ProfileTaskMessageInterface
{
    public function __construct(
        private readonly int $gameProfileId,
        private readonly RedeemableCode $redeemableCode
    )
    {
    }

    public function getGameProfileId(): int
    {
        return $this->gameProfileId;
    }

    public function getRedeemableCode(): RedeemableCode
    {
        return $this->redeemableCode;
    }
}