<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Model\Diary\ZenlessZoneZeroDiaryInfo;
use App\HoyoverseBundle\Model\Diary\ZenlessZoneZeroDiaryItem;
use App\HoyoverseBundle\Model\Game\AutoCodeRedemptionTrait;
use App\HoyoverseBundle\Model\Game\HasAutoCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasDailiesInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\DiaryTrait;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\Game\HasWeekliesInterface;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\Game\HasNotesInterface;
use App\HoyoverseBundle\Model\Game\NotesTrait;
use App\HoyoverseBundle\Model\Game\HasStaminaInterface;
use App\HoyoverseBundle\Model\Game\StaminaTrait;
use App\HoyoverseBundle\Model\Notes\GameNotesDailies;
use App\HoyoverseBundle\Model\Notes\GameNotesProgressMetric;
use App\HoyoverseBundle\Model\Notes\GameNotesStamina;
use App\HoyoverseBundle\Model\Notes\GameNotesStateMetric;
use App\HoyoverseBundle\Model\Notes\GameNotesWeeklies;
use App\HoyoverseBundle\Model\Notes\ZenlessZoneZeroCafe;
use App\HoyoverseBundle\Model\Notes\ZenlessZoneZeroCardSign;
use App\HoyoverseBundle\Model\Notes\ZenlessZoneZeroNotes;
use App\HoyoverseBundle\Model\Notes\ZenlessZoneZeroVhsSale;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Log\LoggerInterface;

/**
 * @implements HasNotesInterface<ZenlessZoneZeroNotes>
 * @implements HasDiaryInterface<ZenlessZoneZeroDiaryInfo, ZenlessZoneZeroDiaryItem>
 */
class ZenlessZoneZeroService extends GameService implements HasNotesInterface, HasDiaryInterface, HasAutoCodeRedemptionInterface, HasStaminaInterface, HasDailiesInterface, HasWeekliesInterface, HasShopStatusInterface
{
    use NotesTrait;
    use DiaryTrait;
    use AutoCodeRedemptionTrait;
    use StaminaTrait;

    public function __construct(
        LoggerInterface $hoyoverseZzzLogger,
    )
    {
        parent::__construct($hoyoverseZzzLogger);
    }

    public static function getType(): string
    {
        return "zenless-zone-zero";
    }

    public static function getGameBiz(): string
    {
        return "nap_global";
    }

    public static function getGameId(): int
    {
        return 8;
    }

    public function getGameName(): string
    {
        return "Zenless Zone Zero";
    }

    public function getAuthor(): string
    {
        return "Eous";
    }

    public function getActId(): string
    {
        return "e202406031448091";
    }

    public function getSignGame(): string
    {
        return "zzz";
    }

    public function getUrlInfo(): string
    {
        return "https://sg-public-api.hoyolab.com/event/luna/zzz/os/info";
    }

    public function getUrlHome(): string
    {
        return "https://sg-public-api.hoyolab.com/event/luna/zzz/os/home";
    }

    public function getUrlSign(): string
    {
        return "https://sg-public-api.hoyolab.com/event/luna/zzz/os/sign";
    }

    protected function getCheckInSuccessMessage(): string
    {
        return "Congratulations Proxy! You have successfully checked in today!~";
    }

    protected function getCheckInSignedMessage(): string
    {
        return "You have already checked in today, Proxy!~";
    }

    public function getUrlNotes(): string
    {
        return "https://sg-act-nap-api.hoyolab.com/event/game_record_zzz/api/zzz/note";
    }

    public function getNotesClass(): string
    {
        return ZenlessZoneZeroNotes::class;
    }

    public function getUrlDiaryInfo(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/nap_ledger/month_info";
    }

    public function getUrlDiaryDetail(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/nap_ledger/month_detail";
    }

    public function getDiaryInfoClass(): string
    {
        return ZenlessZoneZeroDiaryInfo::class;
    }

    public function getDiaryItemClass(): string
    {
        return ZenlessZoneZeroDiaryItem::class;
    }

    public function getDiaryPeriod(\DateTimeInterface $date): string
    {
        return $date->format("Ym");
    }

    public function getUrlFetchRedeemableCodes(): string
    {
        return "https://api.ennead.cc/mihoyo/zenless/codes";
    }

    public function getRedemptionLink(): string
    {
        return "https://zenless.hoyoverse.com/redemption";
    }

    public function getUrlCodeRedemption(): string
    {
        return "https://public-operation-nap.hoyoverse.com/common/apicdkey/api/webExchangeCdkeyRisk";
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
            ->setCurrentStamina($notes->getCurrentEnergy())
            ->setMaxStamina($notes->getMaxEnergy())
            ->setStaminaRecoverTime($notes->getEnergyRestore());
    }

    public function getDailiesData(RuntimeAccountData $account): GameNotesDailies
    {
        $notes = $this->getNotes($account);
        return new GameNotesDailies()
            ->addDaily(
                new GameNotesProgressMetric()
                    ->setName("Engagement Points")
                    ->setCurrentValue($notes->getCurrentVitality())
                    ->setMaxValue($notes->getMaxVitality())
            )
            ->addDaily(
                new GameNotesStateMetric()
                    ->setName("Scratch Card")
                    ->setCurrentValue($notes->getCardSign())
                    ->setTargetValue(ZenlessZoneZeroCardSign::DONE)
            )
            ->addDaily(
                new GameNotesStateMetric()
                    ->setName("Coff Cafe")
                    ->setCurrentValue($notes->getCafeState())
                    ->setTargetValue(ZenlessZoneZeroCafe::DONE)
            );
    }

    public function getWeekliesData(RuntimeAccountData $account): GameNotesWeeklies
    {
        $notes = $this->getNotes($account);
        return new GameNotesWeeklies()
            ->addWeekly(
                new GameNotesProgressMetric()
                    ->setName("Bounty Commissions")
                    ->setCurrentValue($notes->getBountyCommissionNum())
                    ->setMaxValue($notes->getBountyCommissionTotal())
                    ->setUnlocked($notes->isBountyCommissionUnlock())
            )
            ->addWeekly(
                new GameNotesProgressMetric()
                    ->setName("Ridu Weekly Points")
                    ->setCurrentValue($notes->getWeeklyTaskCurPoint())
                    ->setMaxValue($notes->getWeeklyTaskMaxPoint())
                    ->setUnlocked($notes->isWeeklyTaskUnlock())
            );
    }

    public function getShopStatusData(RuntimeAccountData $account): GameNotesStateMetric
    {
        $notes = $this->getNotes($account);
        return new GameNotesStateMetric()
            ->setName("Shop Status")
            ->setCurrentValue($notes->getVhsSaleState())
            ->setTargetValue(ZenlessZoneZeroVhsSale::DONE);
    }
}