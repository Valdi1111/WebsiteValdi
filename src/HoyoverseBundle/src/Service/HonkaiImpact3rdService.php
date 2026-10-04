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
class HonkaiImpact3rdService extends GameService implements HasCodeRedemptionInterface
{
    use CodeRedemptionTrait;

    public const string TYPE = "honkai-impact-3rd";
    public const string GAME_BIZ = "bh3_global";
    public const int GAME_ID = 1;

    public function __construct(
        #[Target('hoyoverse.hi3')]
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

    public function getCheckInSuccessMessage(): string
    {
        return "You have successfully checked in today, Captain~";
    }

    public function getCheckInSignedMessage(): string
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
