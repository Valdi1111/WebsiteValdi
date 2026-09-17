<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Model\Diary\HonkaiStarRailDiaryInfo;
use App\HoyoverseBundle\Model\Diary\HonkaiStarRailDiaryItem;
use App\HoyoverseBundle\Model\Game\AutoCodeRedemptionTrait;
use App\HoyoverseBundle\Model\Game\HasAutoCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasDailiesInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\DiaryTrait;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasWeekliesInterface;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\Game\HasNotesInterface;
use App\HoyoverseBundle\Model\Game\NotesTrait;
use App\HoyoverseBundle\Model\Game\HasStaminaInterface;
use App\HoyoverseBundle\Model\Notes\GameNotesDailies;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\HoyoverseBundle\Model\Notes\GameNotesMetricCheckType;
use App\HoyoverseBundle\Model\Notes\GameNotesProgressMetric;
use App\HoyoverseBundle\Model\Notes\GameNotesStamina;
use App\HoyoverseBundle\Model\Notes\GameNotesWeeklies;
use App\HoyoverseBundle\Model\Notes\HonkaiStarRailNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Log\LoggerInterface;

/**
 * @implements HasNotesInterface<HonkaiStarRailNotes>
 * @implements HasDiaryInterface<HonkaiStarRailDiaryInfo, HonkaiStarRailDiaryItem>
 */
class HonkaiStarRailService extends GameService implements HasNotesInterface, HasDiaryInterface, HasAutoCodeRedemptionInterface, HasStaminaInterface, HasDailiesInterface, HasWeekliesInterface, HasExpeditionsInterface
{
    use NotesTrait;
    use DiaryTrait;
    use AutoCodeRedemptionTrait;

    public function __construct(
        LoggerInterface $hoyoverseHsrLogger,
    )
    {
        parent::__construct($hoyoverseHsrLogger);
    }

    public static function getType(): string
    {
        return "honkai-star-rail";
    }

    public static function getGameBiz(): string
    {
        return "hkrpg_global";
    }

    public static function getGameId(): int
    {
        return 6;
    }

    public function getGameName(): string
    {
        return "Honkai: Star Rail";
    }

    public function getAuthor(): string
    {
        return "PomPom";
    }

    public function getActId(): string
    {
        return "e202303301540311";
    }

    public function getSignGame(): string
    {
        return "hkrpg";
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
        return "You have successfully checked in today, Trailblazer~";
    }

    public function getCheckInSignedMessage(): string
    {
        return "You've already checked in today, Trailblazer~";
    }

    public function getUrlNotes(): string
    {
        return "https://bbs-api-os.hoyolab.com/game_record/hkrpg/api/note";
    }

    public function getNotesClass(): string
    {
        return HonkaiStarRailNotes::class;
    }

    public function getUrlDiaryInfo(): string
    {
        return "https://sg-public-api.hoyolab.com/event/srledger/month_info";
    }

    public function getUrlDiaryDetail(): string
    {
        return "https://sg-public-api.hoyolab.com/event/srledger/month_detail";
    }

    public function getDiaryInfoClass(): string
    {
        return HonkaiStarRailDiaryInfo::class;
    }

    public function getDiaryItemClass(): string
    {
        return HonkaiStarRailDiaryItem::class;
    }

    public function getDiaryPeriod(\DateTimeInterface $date): string
    {
        return $date->format("Ym");
    }

    public function getUrlFetchRedeemableCodes(): string
    {
        return "https://api.ennead.cc/mihoyo/starrail/codes";
    }

    public function getRedemptionLink(): string
    {
        return "https://hsr.hoyoverse.com/gift";
    }

    public function getUrlCodeRedemption(): string
    {
        return "https://public-operation-hkrpg.hoyoverse.com/common/apicdkey/api/webExchangeCdkeyRisk";
    }

    public function getRegenRate(): int
    {
        return 360;
    }

    public function getMaxStamina(): int
    {
        return 300;
    }

    public function getStaminaData(RuntimeAccountData $account): GameNotesStamina
    {
        $notes = $this->getNotes($account);
        return new GameNotesStamina()
            ->setCurrentStamina($notes->getCurrentStamina())
            ->setMaxStamina($notes->getMaxStamina())
            ->setStaminaRecoverTime($notes->getStaminaRecoverTime());
    }

    public function getDailiesData(RuntimeAccountData $account): GameNotesDailies
    {
        $notes = $this->getNotes($account);
        return new GameNotesDailies()
            ->addDaily(
                new GameNotesProgressMetric()
                    ->setName("Activity Points")
                    ->setCurrentValue($notes->getCurrentTrainScore())
                    ->setMaxValue($notes->getMaxTrainScore())
            );
    }

    public function getWeekliesData(RuntimeAccountData $account): GameNotesWeeklies
    {
        $notes = $this->getNotes($account);
        return new GameNotesWeeklies()
            ->addWeekly(
                new GameNotesProgressMetric()
                    ->setName("Weekly Bosses")
                    ->setCurrentValue($notes->getWeeklyCocoonCnt())
                    ->setMaxValue($notes->getWeeklyCocoonLimit())
                    ->setCheckType(GameNotesMetricCheckType::CURRENT_EQUALS_ZERO)
            )
            ->addWeekly(
                new GameNotesProgressMetric()
                    ->setName("Period Points")
                    ->setCurrentValue($notes->getPeriodScore())
                    ->setMaxValue($notes->getPeriodMaxScore())
            );
    }

    public function getExpeditionsData(RuntimeAccountData $account): GameNotesExpeditions
    {
        $notes = $this->getNotes($account);
        $expeditions = new GameNotesExpeditions();
        foreach ($notes->getExpeditions() as $expedition) {
            $expeditions->addExpedition(
                $expedition->getAvatars(),
                strtolower($expedition->getStatus() ?? ""),
                $expedition->getRemainingTime()
            );
        }
        return $expeditions;
    }
}