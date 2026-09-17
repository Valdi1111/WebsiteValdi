<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\CodeRedeemFailedException;
use App\HoyoverseBundle\Model\RedeemableCode;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasAutoCodeRedemptionInterface
 */
trait AutoCodeRedemptionTrait
{
    use CodeRedemptionTrait;

    public function redeemCode(RuntimeAccountData $account, RedeemableCode $redeemableCode): void
    {
        $profile = $account->getGameProfile();

        $this->requestHoyolab(
            method: Request::METHOD_POST,
            url: $this->getUrlCodeRedemption(),
            auth: $account,
            query: [
                'uid' => $profile->getGameUid(),
                'region' => $profile->getRegion(),
                'lang' => 'en',
                'cdkey' => $redeemableCode->getCode(),
                'game_biz' => $profile->getGameBiz(),
                't' => new \DateTimeImmutable()->getTimestamp(),
            ],
            exceptionClass: CodeRedeemFailedException::class
        );

        $this->getLogger()->info("({$profile->getGameUid()}) {$profile->getNickname()} redeemed code: {$redeemableCode->getCode()}");
    }

}