<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Model\Game\CodeRedemptionTrait;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsAlias(GameInterface::class, target: self::TYPE)]
#[AutoconfigureTag(name: 'hoyoverse.game.id', attributes: ['key' => self::GAME_ID])]
#[AutoconfigureTag(name: 'hoyoverse.game.biz', attributes: ['key' => self::GAME_BIZ])]
class TearsOfThemisService extends GameService implements HasCodeRedemptionInterface
{
    use CodeRedemptionTrait;

    public const string TYPE = "tear-of-themis";
    public const string GAME_BIZ = "nxx_global";
    public const int GAME_ID = 0;

    public function __construct(
        #[Target('hoyoverse.tot')]
        LoggerInterface $logger,
    )
    {
        parent::__construct($logger);
    }

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getGameBiz(): string
    {
        return self::GAME_BIZ;
    }

    public static function getGameId(): int
    {
        return self::GAME_ID;
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
