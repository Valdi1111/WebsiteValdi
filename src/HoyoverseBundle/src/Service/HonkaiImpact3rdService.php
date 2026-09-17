<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Model\Game\CodeRedemptionTrait;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use Psr\Log\LoggerInterface;

class HonkaiImpact3rdService extends GameService implements HasCodeRedemptionInterface
{
    use CodeRedemptionTrait;

    public function __construct(
        LoggerInterface $hoyoverseHi3Logger,
    )
    {
        parent::__construct($hoyoverseHi3Logger);
    }

    public static function getType(): string
    {
        return "honkai-impact-3rd";
    }

    public static function getGameBiz(): string
    {
        return "bh3_global";
    }

    public static function getGameId(): int
    {
        return 1;
    }

    public function getGameName(): string
    {
        return "Honkai Impact 3rd";
    }

    public function getAuthor(): string
    {
        return "Kiana";
    }

    public function getActId(): string
    {
        return "e202110291205111";
    }

    public function getSignGame(): string
    {
        return "";
    }

    public function getUrlInfo(): string
    {
        return "https://sg-public-api.hoyolab.com/event/mani/info";
    }

    public function getUrlHome(): string
    {
        return "https://sg-public-api.hoyolab.com/event/mani/home";
    }

    public function getUrlSign(): string
    {
        return "https://sg-public-api.hoyolab.com/event/mani/sign";
    }

    protected function getCheckInSuccessMessage(): string
    {
        return "You have successfully checked in today, Captain~";
    }

    protected function getCheckInSignedMessage(): string
    {
        return "You've already checked in today, Captain~";
    }

    public function getCodeRedemptionManualReason(): string
    {
        return "Redeem this code via the in-game exchange center.";
    }

    public function getUrlFetchRedeemableCodes(): string
    {
        return "https://api.ennead.cc/mihoyo/honkai/codes";
    }
}