<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Model\Game\CodeRedemptionTrait;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use Psr\Log\LoggerInterface;

class TearsOfThemisService extends GameService implements HasCodeRedemptionInterface
{
    use CodeRedemptionTrait;

    public function __construct(
        LoggerInterface $hoyoverseTotLogger,
    )
    {
        parent::__construct($hoyoverseTotLogger);
    }

    public static function getType(): string
    {
        return "tear-of-themis";
    }

    public static function getGameBiz(): string
    {
        return "nxx_global";
    }

    public static function getGameId(): int
    {
        return 0;
    }

    public function getGameName(): string
    {
        return "Tears of Themis";
    }

    public function getAuthor(): string
    {
        return "Luke";
    }

    public function getActId(): string
    {
        return "e202202281857121";
    }

    public function getSignGame(): string
    {
        return "";
    }

    public function getUrlInfo(): string
    {
        return "https://sg-public-api.hoyolab.com/event/luna/os/info";
    }

    public function getUrlHome(): string
    {
        return "https://sg-public-api.hoyolab.com/event/luna/os/home";
    }

    public function getUrlSign(): string
    {
        return "https://sg-public-api.hoyolab.com/event/luna/os/sign";
    }

    public function getCheckInSuccessMessage(): string
    {
        return "Successfully signed in";
    }

    public function getCheckInSignedMessage(): string
    {
        return "Already signed in today";
    }

    public function getCodeRedemptionManualReason(): string
    {
        return "Redeem this code from the in-game Exchange menu.";
    }

    public function getUrlFetchRedeemableCodes(): string
    {
        return "https://api.ennead.cc/mihoyo/themis/codes";
    }
}