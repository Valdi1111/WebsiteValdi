<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Model\Diary\GenshinImpactDiaryInfo;
use App\HoyoverseBundle\Model\Diary\GenshinImpactDiaryItem;
use App\HoyoverseBundle\Model\Game\AutoCodeRedemptionTrait;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\GenshinImpactEndgamesTrait;
use App\HoyoverseBundle\Model\Game\HasAutoCodeRedemptionInterface;
use App\CoreBundle\Model\Game\HasDailiesInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\DiaryTrait;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\CoreBundle\Model\Game\HasWeekliesInterface;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\Game\HasPowershellPullImportInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Model\Game\HoyoversePullTrait;
use App\HoyoverseBundle\Model\Game\PowershellPullConfig;
use App\CoreBundle\Model\Game\HasNotesInterface;
use App\HoyoverseBundle\Model\Game\NotesTrait;
use App\CoreBundle\Model\Game\HasStaminaInterface;
use App\CoreBundle\Model\GameNotes\GameNotesDailies;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\CoreBundle\Model\GameNotes\GameNotesMetricCheckType;
use App\CoreBundle\Model\GameNotes\GameNotesProgressMetric;
use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\CoreBundle\Model\GameNotes\GameNotesStamina;
use App\CoreBundle\Model\GameNotes\GameNotesWeeklies;
use App\HoyoverseBundle\Model\Notes\GenshinImpactNotes;
use App\CoreBundle\Model\Game\RuntimeAccountDataInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * @implements HasNotesInterface<GenshinImpactNotes>
 * @implements HasDiaryInterface<GenshinImpactDiaryInfo, GenshinImpactDiaryItem>
 */
#[AsAlias(GameInterface::class, target: self::TYPE)]
#[AutoconfigureTag(name: 'hoyoverse.game.id', attributes: ['key' => self::GAME_ID])]
#[AutoconfigureTag(name: 'hoyoverse.game.biz', attributes: ['key' => self::GAME_BIZ])]
class GenshinImpactService extends GameService implements HasNotesInterface, HasDiaryInterface, HasAutoCodeRedemptionInterface, HasStaminaInterface, HasDailiesInterface, HasWeekliesInterface, HasExpeditionsInterface, HasRealmCurrencyInterface, HasStokenPullImportInterface, HasPowershellPullImportInterface
{
    use NotesTrait;
    use DiaryTrait;
    use AutoCodeRedemptionTrait;
    use GenshinImpactEndgamesTrait;
    use HoyoversePullTrait;

    public const string TYPE = "genshin-impact";
    public const string GAME_BIZ = "hk4e_global";
    public const int GAME_ID = 2;

    public function __construct(
        #[Target('hoyoverse.gi')]
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
        return "Genshin Impact";
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

    public function getCheckInSuccessMessage(): string
    {
        return "Congratulations, Traveler! You have successfully checked in today~";
    }

    public function getCheckInSignedMessage(): string
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

    public function getDiaryInfoClass(): string
    {
        return GenshinImpactDiaryInfo::class;
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

    public function getStaminaData(RuntimeAccountDataInterface $account): GameNotesStamina
    {
        $notes = $this->getNotes($account);
        return new GameNotesStamina()
            ->setCurrentStamina($notes->getCurrentResin())
            ->setMaxStamina($notes->getMaxResin())
            ->setStaminaRecoverTime($notes->getResinRecoveryTime());
    }

    public function getDailiesData(RuntimeAccountDataInterface $account): GameNotesDailies
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

    public function getWeekliesData(RuntimeAccountDataInterface $account): GameNotesWeeklies
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

    public function getAuthkeyGameBiz(): string
    {
        return self::GAME_BIZ;
    }

    public function getAuthkeyAppId(): string
    {
        return 'webview_gacha';
    }

    public function getPowershellPullConfig(): PowershellPullConfig
    {
        return new PowershellPullConfig(
            localLowFolders: ['miHoYo\\Genshin Impact', 'miHoYo\\原神'],
            logFiles: ['output_log.txt', 'output_log.txt.last', 'Player.log', 'Player-prev.log'],
            dataFolderPattern: 'GenshinImpact_Data|YuanShen_Data',
            urlRegex: '/gacha_info/api/getGachaLog|game_biz=hk4e_|/genshin/',
        );
    }

    public function getGachaEndpointUrl(): string
    {
        return 'https://public-operation-hk4e-sg.hoyoverse.com/gacha_info/api/getGachaLog';
    }

    public function getGachaBannerTypes(): array
    {
        return [
            301 => 'Character Event Wish',
            302 => 'Weapon Event Wish',
            200 => 'Standard Wish',
            100 => "Beginners' Wish",
            500 => 'Chronicled Wish',
        ];
    }

    public function getGachaBannerGroups(): array
    {
        return [
            '301' => [
                'key' => '301',
                'name' => 'Character Event Wish',
                'gacha_types' => ['301', '400'],
                's_max_pity' => 90,
                'a_max_pity' => 10,
            ],
            '302' => [
                'key' => '302',
                'name' => 'Weapon Event Wish',
                'gacha_types' => ['302'],
                's_max_pity' => 80,
                'a_max_pity' => 10,
            ],
            '200' => [
                'key' => '200',
                'name' => 'Standard Wish',
                'gacha_types' => ['200'],
                's_max_pity' => 90,
                'a_max_pity' => 10,
            ],
            '500' => [
                'key' => '500',
                'name' => 'Chronicled Wish',
                'gacha_types' => ['500'],
                's_max_pity' => 90,
                'a_max_pity' => 10,
            ],
            '100' => [
                'key' => '100',
                'name' => "Beginners' Wish",
                'gacha_types' => ['100'],
                's_max_pity' => 20,
                'a_max_pity' => 10,
            ],
        ];
    }
}
