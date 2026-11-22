<?php

namespace App\PerfectworldBundle\Service;

use App\GamesBundle\Model\Game\GachaBannerConfig;
use App\GamesBundle\Model\Game\GachaConfig;
use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Game\RarityTierConfig;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Game\HasPullTrackerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AutoconfigureTag(name: 'perfectworld.game.biz', attributes: ['key' => self::GAME_BIZ])]
#[AutoconfigureTag(name: 'perfectworld.game.id', attributes: ['key' => self::GAME_ID])]
class NevernessToEvernessService implements GameInterface, HasPullTrackerInterface, HasManualPullsInterface
{
    public const int GAME_ID = 1;
    public const string GAME_BIZ = 'nte';
    public const string GAME_NAME = 'Neverness to Everness';

    /** Limited character boards: pity and Points Gift progress are shared by all limited boards. */
    public const string BANNER_LIMITED = 'Lottery_LimitedCharacter';
    /** Standard character board. */
    public const string BANNER_STANDARD = 'Lottery_Permanent';
    /** Arc Research Program (Miracle Box). */
    public const string BANNER_ARC = 'Arc_MiracleBox';

    public const int RARITY_S = 5;
    public const int RARITY_A = 4;
    public const int RARITY_B = 3;

    /** SDK server id => region code. */
    private const array REGIONS = [
        '23001' => 'AS',
        '23002' => 'NA_SA',
        '23003' => 'EU',
        '23004' => 'SEA',
    ];

    /** Fixed server offsets (no DST), as used by community trackers. */
    private const array TIMEZONES = [
        'AS' => 'Etc/GMT-8',
        'NA_SA' => 'Etc/GMT+5',
        'EU' => 'Etc/GMT-1',
        'SEA' => 'Etc/GMT-8',
    ];

    public function __construct(
        #[Target('perfectworld')]
        private readonly LoggerInterface $logger,
    ) {
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
        return self::GAME_NAME;
    }

    public function getIcon(): ?string
    {
        return null;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getRegionForServer(?string $serverId): ?string
    {
        return self::REGIONS[(string) $serverId] ?? null;
    }

    public function getTimezoneForRegion(?string $region): string
    {
        return self::TIMEZONES[(string) $region] ?? 'UTC';
    }

    public static function isBoardBanner(string $bannerKey): bool
    {
        return $bannerKey === self::BANNER_LIMITED || $bannerKey === self::BANNER_STANDARD;
    }

    public function getGachaConfig(): GachaConfig
    {
        return new GachaConfig('', [
            new GachaBannerConfig(self::BANNER_LIMITED, 'Scarborough Fair (Limited Boards)', [self::BANNER_LIMITED], 90, 10),
            new GachaBannerConfig(self::BANNER_STANDARD, 'Strange Encounters (Standard Board)', [self::BANNER_STANDARD], 90, 10),
            new GachaBannerConfig(self::BANNER_ARC, 'Arc Research Program', [self::BANNER_ARC], 60, 10),
        ]);
    }

    public function getRarityConfig(): RarityConfig
    {
        return new RarityConfig(
            s: new RarityTierConfig(self::RARITY_S, 'S-Rank', 'S'),
            a: new RarityTierConfig(self::RARITY_A, 'A-Rank', 'A'),
            b: new RarityTierConfig(self::RARITY_B, 'B-Rank', 'B'),
            // Generic label: the monthly chart mixes board rolls and Arcs
            pullLabel: 'Pulls',
            pullSingularLabel: 'Pull',
            sResetsAPity: false,
        );
    }

    public function getBannerPullLabels(string $bannerKey): array
    {
        return self::isBoardBanner($bannerKey)
            ? ['plural' => 'Rolls', 'singular' => 'Roll']
            : ['plural' => 'Arcs', 'singular' => 'Arc'];
    }
}
