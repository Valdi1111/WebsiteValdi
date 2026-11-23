<?php

namespace App\HoyoverseBundle\Service;

use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Diary\HonkaiStarRailDiaryInfo;
use App\HoyoverseBundle\Model\Diary\HonkaiStarRailDiaryItem;
use App\HoyoverseBundle\Model\Game\AutoCodeRedemptionTrait;
use App\GamesBundle\Model\Game\GachaBannerConfig;
use App\GamesBundle\Model\Game\GachaConfig;
use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Game\RarityTierConfig;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasAutoCodeRedemptionInterface;
use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\DiaryTrait;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\Game\HasPowershellPullImportInterface;
use App\HoyoverseBundle\Model\Game\HoyoversePullTrait;
use App\HoyoverseBundle\Model\Game\PowershellPullConfig;
use App\GamesBundle\Model\Game\HasNotesInterface;
use App\HoyoverseBundle\Model\Game\HonkaiStarRailEndgamesTrait;
use App\HoyoverseBundle\Model\Game\NotesTrait;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\GamesBundle\Model\GameNotes\GameNotesMetricCheckType;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use App\HoyoverseBundle\Model\Notes\HonkaiStarRailNotes;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * @implements HasNotesInterface<HonkaiStarRailNotes>
 * @implements HasDiaryInterface<HonkaiStarRailDiaryInfo, HonkaiStarRailDiaryItem>
 */
#[AsAlias(GameInterface::class, target: self::TYPE)]
#[AutoconfigureTag(name: 'hoyoverse.game.id', attributes: ['key' => self::GAME_ID])]
#[AutoconfigureTag(name: 'hoyoverse.game.biz', attributes: ['key' => self::GAME_BIZ])]
class HonkaiStarRailService extends GameService implements HasNotesInterface, HasDiaryInterface, HasAutoCodeRedemptionInterface, HasStaminaInterface, HasDailiesInterface, HasWeekliesInterface, HasExpeditionsInterface, HasPowershellPullImportInterface, HasManualPullsInterface
{
    use NotesTrait;
    use DiaryTrait;
    use AutoCodeRedemptionTrait;
    use HonkaiStarRailEndgamesTrait;
    use HoyoversePullTrait;

    public const string TYPE = "honkai-star-rail";
    public const string GAME_BIZ = "hkrpg_global";
    public const int GAME_ID = 6;

    public function __construct(
        #[Target('hoyoverse.hsr')]
        LoggerInterface $hoyoverseHsrLogger,
    )
    {
        parent::__construct($hoyoverseHsrLogger);
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

    public function getDiaryCurrencies(): array
    {
        return [GameDiaryCurrency::STELLAR_JADE, GameDiaryCurrency::STAR_RAIL_PASS];
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

    public function getStaminaData(RuntimeAccountDataInterface $account): GameNotesStamina
    {
        $notes = $this->getNotes($account);
        return new GameNotesStamina()
            ->setCurrentStamina($notes->getCurrentStamina())
            ->setMaxStamina($notes->getMaxStamina())
            ->setStaminaRecoverTime($notes->getStaminaRecoverTime());
    }

    public function getDailiesData(RuntimeAccountDataInterface $account): GameNotesDailies
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

    public function getWeekliesData(RuntimeAccountDataInterface $account): GameNotesWeeklies
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
        foreach ($notes->getExpeditions() ?? [] as $expedition) {
            $expeditions->addExpedition(
                // An assignment has up to two characters: the first one stands for it
                $expedition->getAvatars()[0] ?? null,
                strtolower($expedition->getStatus() ?? ""),
                $expedition->getRemainingTime()
            );
        }
        return $expeditions;
    }

    public function getPowershellPullConfig(): PowershellPullConfig
    {
        return new PowershellPullConfig(
            localLowFolders: ['Cognosphere\\Star Rail', 'miHoYo\\崩坏：星穹铁道', 'miHoYo\\Star Rail', 'miHoYo\\StarRail'],
            logFiles: ['Player.log', 'Player-prev.log', 'output_log.txt'],
            dataFolderPattern: 'StarRail_Data',
            urlRegex: '/common/hkrpg_gacha_record/api/get(?:Ld)?GachaLog|game_biz=hkrpg_',
        );
    }

    private const string GACHA_ENDPOINT_URL = 'https://public-operation-hkrpg-sg.hoyoverse.com/common/hkrpg_gacha_record/api/getGachaLog';
    private const string COLLAB_GACHA_ENDPOINT_URL = 'https://public-operation-hkrpg-sg.hoyoverse.com/common/hkrpg_gacha_record/api/getLdGachaLog';

    public function getGachaConfig(): GachaConfig
    {
        return new GachaConfig(
            defaultEndpointUrl: self::GACHA_ENDPOINT_URL,
            banners: [
                new GachaBannerConfig('11', 'Character Event Warp', ['11'], 90, 10),
                new GachaBannerConfig('12', 'Light Cone Event Warp', ['12'], 80, 10),
                new GachaBannerConfig('21', 'Collaboration Character Warp', ['21'], 90, 10, self::COLLAB_GACHA_ENDPOINT_URL),
                new GachaBannerConfig('22', 'Collaboration Light Cone Warp', ['22'], 80, 10, self::COLLAB_GACHA_ENDPOINT_URL),
                new GachaBannerConfig('1', 'Regular Warp', ['1'], 90, 10),
                new GachaBannerConfig('2', 'Departure Warp', ['2'], 50, 10),
            ],
        );
    }

    public function getRarityConfig(): RarityConfig
    {
        return new RarityConfig(
            s: new RarityTierConfig(5, '5-Star', '5★'),
            a: new RarityTierConfig(4, '4-Star', '4★'),
            b: new RarityTierConfig(3, '3-Star', '3★'),
            pullLabel: 'Warps',
            pullSingularLabel: 'Warp',
            sResetsAPity: false,
        );
    }
}
