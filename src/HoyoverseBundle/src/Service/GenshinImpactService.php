<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Model\Diary\GenshinImpactDiaryItem;
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
use App\HoyoverseBundle\Model\Notes\GameNotesDailies;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\HoyoverseBundle\Model\Notes\GameNotesMetricCheckType;
use App\HoyoverseBundle\Model\Notes\GameNotesProgressMetric;
use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\HoyoverseBundle\Model\Notes\GameNotesStamina;
use App\HoyoverseBundle\Model\Notes\GameNotesWeeklies;
use App\HoyoverseBundle\Model\Notes\GenshinImpactNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Log\LoggerInterface;

/**
 * @implements HasNotesInterface<GenshinImpactNotes>
 */
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

    public function getDiaryItemClass(): string
    {
        return GenshinImpactDiaryItem::class;
    }

    public function getDiaryPeriod(\DateTimeInterface $date): string
    {
        return $date->format("m");
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

    public function getStaminaData(RuntimeAccountData $account): GameNotesStamina
    {
        $notes = $this->getNotes($account);
        return new GameNotesStamina()
            ->setCurrentStamina($notes->getCurrentResin())
            ->setMaxStamina($notes->getMaxResin())
            ->setStaminaRecoverTime($notes->getResinRecoveryTime());
    }

    public function getDailiesData(RuntimeAccountData $account): GameNotesDailies
    {
        $notes = $this->getNotes($account);
        return new GameNotesDailies()
            ->addDaily(
                new GameNotesProgressMetric()
                    ->setName("Daily Tasks")
                    ->setCurrentValue($notes->getFinishedTaskNum())
                    ->setMaxValue($notes->getTotalTaskNum())
            );
    }

    public function getWeekliesData(RuntimeAccountData $account): GameNotesWeeklies
    {
        $notes = $this->getNotes($account);
        return new GameNotesWeeklies()
            ->addWeekly(
                new GameNotesProgressMetric()
                    ->setName("Resin Discounts")
                    ->setCurrentValue($notes->getRemainResinDiscountNum())
                    ->setMaxValue($notes->getResinDiscountNumLimit())
                    ->setCheckType(GameNotesMetricCheckType::CURRENT_EQUALS_ZERO)
            );
    }

    public function getExpeditionsData(RuntimeAccountData $account): GameNotesExpeditions
    {
        $notes = $this->getNotes($account);
        $expeditions = new GameNotesExpeditions();
        foreach ($notes->getExpeditions() as $expedition) {
            $expeditions->addExpedition(
                $expedition->getAvatarSideIcon(),
                strtolower($expedition->getStatus() ?? ""),
                $expedition->getRemainedTime()
            );
        }
        return $expeditions;
    }

    public function getRealmData(RuntimeAccountData $account): GameNotesRealm
    {
        $notes = $this->getNotes($account);
        return new GameNotesRealm()
            ->setCurrentCoin($notes->getCurrentHomeCoin())
            ->setMaxCoin($notes->getMaxHomeCoin())
            ->setCoinRecoverTime($notes->getHomeCoinRecoveryTime());
    }
}