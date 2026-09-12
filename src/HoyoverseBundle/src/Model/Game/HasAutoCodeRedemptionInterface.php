<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\RedeemableCode;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasAutoCodeRedemptionInterface extends HasCodeRedemptionInterface
{
    public function getRedemptionLink(): string;

    public function getUrlCodeRedemption(): string;

    public function redeemCode(RuntimeAccountData $account, RedeemableCode $redeemableCode): void;
}