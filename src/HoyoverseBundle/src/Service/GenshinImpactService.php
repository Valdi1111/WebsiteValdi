<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Model\Game\AutoCodeRedemptionTrait;
use App\HoyoverseBundle\Model\Game\HasAutoCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasDailiesInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\DiaryTrait;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasRealmInterface;
use App\HoyoverseBundle\Model\Game\HasWeekliesInterface;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\Game\HasNotesInterface;
use App\HoyoverseBundle\Model\Game\NotesTrait;
use App\HoyoverseBundle\Model\Game\HasStaminaInterface;
use App\HoyoverseBundle\Model\Game\StaminaTrait;
use App\HoyoverseBundle\Model\Notes\GenshinImpactNotes;
use Psr\Log\LoggerInterface;

class GenshinImpactService extends GameService implements HasNotesInterface, HasDiaryInterface, HasAutoCodeRedemptionInterface, HasStaminaInterface, HasDailiesInterface, HasWeekliesInterface, HasExpeditionsInterface, HasRealmInterface
{
    use NotesTrait;
    use DiaryTrait;
    use AutoCodeRedemptionTrait;
    use StaminaTrait;

    public function __construct(
        LoggerInterface $hoyoverseGiLogger,
    )
    {
        parent::__construct($hoyoverseGiLogger);
    }

    public static function getType(): string
    {
        return "genshin-impact";
    }

    public static function getGameBiz(): string
    {
        return "hk4e_global";
    }

    public static function getGameId(): int
    {
        return 2;
    }

    public function getPlatform(): string
    {
        return "genshin";
    }

    public function getFullName(): string
    {
        return "GenshinImpact";
    }

    public function getGameName(): string
    {
        return "Genshin Impact";
    }

    public function getGameShortName(): string
    {
        return "GI";
    }

    public function getAuthor(): string
    {
        return "Paimon";
    }

    public function getActId(): string
    {
        return "e202102251931481";
    }

    public function getSignGame(): string
    {
        return "hk4e";
    }

    public function getUrlInfo(): string
    {
        return "https://sg-hk4e-api.hoyolab.com/event/sol/info";
    }

    public function getUrlHome(): string
    {
        return "https://sg-hk4e-api.hoyolab.com/event/sol/home";
    }

    public function getUrlSign(): string
    {
        return "https://sg-hk4e-api.hoyolab.com/event/sol/sign";
    }

    protected function getCheckInSuccessMessage(): string
    {
        return "Congratulations, Traveler! You have successfully checked in today~";
    }

    protected function getCheckInSignedMessage(): string
    {
        return "Traveler, you've already checked in today~";
    }

    public function getUrlNotes(): string
    {
        return "https://bbs-api-os.mihoyo.com/game_record/genshin/api/dailyNote";
    }

    public function getNotesClass(): string
    {
        return GenshinImpactNotes::class;
    }

    public function getUrlDiaryInfo(): string
    {
        return "https://sg-hk4e-api.hoyolab.com/event/ysledgeros/month_info";
    }

    public function getUrlDiaryDetail(): string
    {
        return "https://sg-hk4e-api.hoyolab.com/event/ysledgeros/month_detail";
    }

    public function getDiaryMonth(int $month, int $year): int|string
    {
        return $month;
    }

    public function getUrlFetchRedeemableCodes(): string
    {
        return "https://api.ennead.cc/mihoyo/genshin/codes";
    }

    public function getRedemptionLink(): string
    {
        return "https://genshin.hoyoverse.com/en/gift";
    }

    public function getUrlCodeRedemption(): string
    {
        return "https://public-operation-hk4e.hoyoverse.com/common/apicdkey/api/webExchangeCdkeyRisk";
    }

    public function getRegenRate(): int
    {
        return 480;
    }

    public function getMaxStamina(): int
    {
        return 200;
    }
}