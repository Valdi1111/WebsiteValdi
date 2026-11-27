<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\Catalog\NteCatalogBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class NteCatalogBuilderTest extends TestCase
{
    private const array PREVIOUS_CATALOG = [
        'sha' => 'ffffffffffffffffffffffffffffffffffffffff',
        'version' => '1.5.0',
        'built_at' => '2026-11-01T06:00:00+01:00',
        'items' => [
            'old_item' => ['id' => 'Old_item', 'name' => 'Old Item', 'rank' => 'B', 'type' => 'item', 'icon_path' => 'UI_Icon/Item/old.png'],
            '1042' => ['id' => '1042', 'name' => 'Outdated Name', 'rank' => null, 'type' => 'character', 'icon_path' => null],
        ],
        'avatars' => ['old_avatar' => 'UI_Icon/AvatarImage/256/old_256.png'],
        'pools' => ['lottery_old' => 'Old Board'],
        'banners' => [
            'lottery_old' => ['id' => 'Lottery_Old', 'category' => 'board', 'name' => 'Old Board', 'up' => ['1001'], 'others' => [], 'up_guarantee' => null],
            'forklottery_heiyu' => ['id' => 'ForkLottery_HeiYu', 'category' => 'arc', 'name' => 'Outdated Special', 'up' => ['fork_old'], 'others' => [], 'up_guarantee' => 60],
        ],
    ];

    /** UTC runs of the irminsul.gg phases (11:00 to 05:59:59 UTC+8), by version */
    private const array RUNS = [
        '1.0.1' => ['start_at' => '2026-04-29 03:00:00', 'end_at' => '2026-05-12 21:59:59'],
        '1.0.2' => ['start_at' => '2026-05-13 03:00:00', 'end_at' => '2026-06-02 21:59:59'],
        '1.1.1' => ['start_at' => '2026-06-03 03:00:00', 'end_at' => '2026-06-23 21:59:59'],
        '1.1.2' => ['start_at' => '2026-06-24 03:00:00', 'end_at' => '2026-07-07 21:59:59'],
        '1.2.1' => ['start_at' => '2026-07-08 03:00:00', 'end_at' => '2026-07-28 21:59:59'],
        '1.2.2' => ['start_at' => '2026-07-29 03:00:00', 'end_at' => '2026-08-18 21:59:59'],
        '1.3.1' => ['start_at' => '2026-08-19 03:00:00', 'end_at' => '2026-09-08 21:59:59'],
        '1.3.2' => ['start_at' => '2026-09-09 03:00:00', 'end_at' => '2026-09-29 21:59:59'],
        '1.4.1' => ['start_at' => '2026-09-30 03:00:00', 'end_at' => '2026-10-20 21:59:59'],
        '1.4.2' => ['start_at' => '2026-10-21 03:00:00', 'end_at' => '2026-11-10 21:59:59'],
        '1.5.1' => ['start_at' => '2026-11-11 03:00:00', 'end_at' => '2026-12-01 21:59:59'],
        '1.5.2' => ['start_at' => '2026-12-02 03:00:00', 'end_at' => '2026-12-22 21:59:59'],
    ];

    private const string FEATURED_KEY = 'BPUI_LotteryModuleEntrance_BigGurantten';
    private const string POOL_TABLE = 'DataTable/Fork/DT_ForkLotteryPoolData.json';
    private const string BOARD_CELL_TABLE = 'DataTable/Monopoly/DT_MonopolyCellDataTable.json';
    private const string GACHA_ILLUSTRATE_TABLE = 'DataTable/Gacha/GachaIllustrate.json';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    public function testBuildsCompactCatalogFromTablesAtTheCommit(): void
    {
        $fixture = new NteAssetsFixture();
        $catalog = $this->createBuilder($fixture)->build();

        $this->assertSame(NteAssetsFixture::SHA, $catalog['sha']);
        $this->assertSame('1.5.4', $catalog['version']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $catalog['built_at']));

        // The API only resolves the ref, every file is read at the resolved SHA
        $this->assertSame('https://api.github.com/repos/Waifus-Grace/NTE_Assets/commits/main', $fixture->requestedUrls[0]);
        $this->assertSame(['application/vnd.github.sha'], $fixture->apiAcceptHeaders);
        foreach (array_slice($fixture->requestedUrls, 1) as $url) {
            $this->assertStringStartsWith(NteAssetsFixture::RAW_PREFIX, $url);
        }
        $this->assertSame('README.md', $fixture->requestedRawPaths()[0]);

        $items = $catalog['items'];

        // Names: namespace lookup beats the stale embedded string, key-only and embedded fallbacks
        $this->assertSame(['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'UI_Icon/AvatarImage/200/player_heiyu.png', 'preview_path' => 'UI/Appearance/Fashion/1024/YH_lihui_fashionshop_heiyu.png'], $items['1042']);
        $this->assertSame('Zankou', $items['1024']['name']);
        $this->assertSame('A', $items['1024']['rank']);
        $this->assertSame('Embedded Name', $items['1098']['name']);
        $this->assertArrayNotHasKey('1099', $items);

        // Arcs: icon file name not derived from the id, key found outside its namespace
        $this->assertSame(['id' => 'fork_twinbirds', 'name' => 'Crime and Punishment', 'rank' => 'S', 'type' => 'arc', 'icon_path' => 'UI_Icon/Fork/fork_twinbirds_256.png', 'preview_path' => 'UI_Icon/Fork/1024/fork_twinbirds.png'], $items['fork_twinbirds']);
        $this->assertSame(['id' => 'fork_vine', 'name' => 'Be Happy', 'rank' => 'B', 'type' => 'arc', 'icon_path' => 'UI_Icon/Fork/fork_HugVine_256.png', 'preview_path' => 'UI_Icon/Fork/1024/fork_HugVine.png'], $items['fork_vine']);

        // Items: path kept with its case, object name ignored
        $this->assertSame(['id' => 'DiceNormal', 'name' => 'Fabricated Dice', 'rank' => 'S', 'type' => 'item', 'icon_path' => 'UI_Icon/Item/DiceNormal.png', 'preview_path' => null], $items['dicenormal']);
        $this->assertSame('item', $items['annulith']['type']);
        $this->assertNull($items['green_item']['rank']);
        $this->assertNull($items['bad_icon']['icon_path']);

        // Cosmetics: Fashion_ prefix, cosmetic item types, vehicle and appearance tables
        $this->assertSame(['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI/Appearance/Glider/Fashion_Glide_1075.png', 'preview_path' => 'UI/Gacha/Lottery_Img/Yiluoyi/YH_lihui_1024_Yiluoyi1.png'], $items['fashion_glide_1075']);
        $this->assertSame('cosmetic', $items['glide_1']['type']);
        $this->assertSame(['id' => 'vehicle002', 'name' => 'Griffin', 'rank' => 'S', 'type' => 'cosmetic', 'icon_path' => 'UI_Icon/Item/YH_UI_CarCard1.png', 'preview_path' => null], $items['vehicle002']);
        $this->assertSame('UI_Icon/AvatarImage/256/player_sakiri_256.png', $items['fashion_1003_0']['icon_path']);

        // DT_ItemConfig sets type and name, DT_AppearanceData fills the missing rank and icon
        $this->assertSame(['id' => 'Fashion_1042_1', 'name' => 'Wandering Stars', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI_Icon/AvatarImage/256/player_heiyu1_256.png', 'preview_path' => null], $items['fashion_1042_1']);

        $this->assertSame([
            'fashion_1042_1' => 'UI_Icon/AvatarImage/256/player_heiyu1_256.png',
            '1042' => 'UI_Icon/AvatarImage/256/player_heiyu_256.png',
        ], $catalog['avatars']);

        $this->assertSame('Foretold Special', $catalog['pools']['forklottery_heiyu']);
        $this->assertArrayNotHasKey('1', $catalog['pools']);
        $this->assertSame('Foretold Finale', $catalog['pools']['lottery_heiyu']);
        $this->assertSame('Fading Reverie', $catalog['pools']['lottery_anhunqu']);
        $this->assertSame('Strange Encounters', $catalog['pools']['lottery_permanent']);
    }

    public function testPreviewPathsAreTheFullArtworks(): void
    {
        $fixture = new NteAssetsFixture();
        $items = $this->createBuilder($fixture, null, $this->createLogger())->build()['items'];

        // Characters: gacha splash, outfit art in the appearance folder or in the 1024px icon folder
        $this->assertSame('UI/Gacha/YH_lihui_character_zankou.png', $items['1024']['preview_path']);
        $this->assertSame('UI/Appearance/Fashion/1024/YH_lihui_fashionshop_heiyu.png', $items['1042']['preview_path']);
        $this->assertSame('UI_Icon/Appearance/1098/Fashion_1098_0/1024_Fashion_1098_0.png', $items['1098']['preview_path']);
        $this->assertArrayNotHasKey('1099', $items);
        $this->assertArrayNotHasKey('not_in_catalog', $items);

        // Arcs: GachaIllustrate repeats the 256px icon (key in another case for fork_vine), so the 1024px version
        // of the icon file is used, whatever the Arc id
        $this->assertSame('UI_Icon/Fork/1024/fork_twinbirds.png', $items['fork_twinbirds']['preview_path']);
        $this->assertSame('UI_Icon/Fork/1024/fork_HugVine.png', $items['fork_vine']['preview_path']);

        // Cosmetic illustrated under a key in another case
        $this->assertSame('UI/Gacha/Lottery_Img/Yiluoyi/YH_lihui_1024_Yiluoyi1.png', $items['fashion_glide_1075']['preview_path']);

        // No artwork: the illustration is the icon (in any case, also when a later table fills that icon), not a
        // UI texture, no row, or no icon to derive from
        $this->assertNull($items['dicenormal']['preview_path']);
        $this->assertNull($items['fashion_1042_1']['preview_path']);
        $this->assertNull($items['annulith']['preview_path']);
        $this->assertNull($items['vehicle002']['preview_path']);
        $this->assertNull($items['green_item']['preview_path']);
        $this->assertNull($items['bad_icon']['preview_path']);

        // Downloaded once, at the commit, without warning
        $this->assertSame(1, array_count_values($fixture->requestedRawPaths())[self::GACHA_ILLUSTRATE_TABLE]);
        $this->assertSame([], $this->skippedTableWarnings(self::GACHA_ILLUSTRATE_TABLE));
    }

    public function testMissingGachaIllustrateLeavesOnlyTheArcPreviews(): void
    {
        $fixture = new NteAssetsFixture([self::GACHA_ILLUSTRATE_TABLE => null]);
        $items = $this->createBuilder($fixture, null, $this->createLogger())->build()['items'];

        $this->assertNull($items['1024']['preview_path']);
        $this->assertNull($items['1042']['preview_path']);
        $this->assertNull($items['fashion_glide_1075']['preview_path']);
        // Derived from the icon, without the table
        $this->assertSame('UI_Icon/Fork/1024/fork_twinbirds.png', $items['fork_twinbirds']['preview_path']);
        $this->assertSame('UI_Icon/Fork/1024/fork_HugVine.png', $items['fork_vine']['preview_path']);

        $this->assertSame([['path' => self::GACHA_ILLUSTRATE_TABLE, 'sha' => NteAssetsFixture::SHA]], $this->skippedTableWarnings(self::GACHA_ILLUSTRATE_TABLE));
    }

    public function testArcIconsOutsideThe256pxShapeHaveNoPreview(): void
    {
        $fixture = new NteAssetsFixture([
            'DataTable/Fork/DT_ForkItemData.json' => NteAssetsFixture::table('DT_ForkItemData', [
                'fork_twinbirds' => NteAssetsFixture::itemRow(NteAssetsFixture::text('/Game/Text/ST_Fork.ST_Fork', 'fork_twinbirds_name'), 'ORANGE', '/Game/UI/UI_Icon/Fork/fork_twinbirds.fork_twinbirds', 'EItemType::ITEM_TYPE_FORK'),
                'fork_vine' => NteAssetsFixture::itemRow(NteAssetsFixture::text('/Game/Text/ST_Fork.ST_Fork', 'fork_moved_name'), 'BLUE', '/Game/UI/UI_Icon/Item/fork_HugVine_256.fork_HugVine_256', 'EItemType::ITEM_TYPE_FORK'),
            ]),
            self::GACHA_ILLUSTRATE_TABLE => null,
        ]);

        $items = $this->createBuilder($fixture)->build()['items'];

        $this->assertNull($items['fork_twinbirds']['preview_path']);
        $this->assertNull($items['fork_vine']['preview_path']);
    }

    public function testExplicitRefIsResolvedToItsSha(): void
    {
        $fixture = new NteAssetsFixture();
        $catalog = $this->createBuilder($fixture)->build('v1.5.4');

        $this->assertSame('https://api.github.com/repos/Waifus-Grace/NTE_Assets/commits/v1.5.4', $fixture->requestedUrls[0]);
        $this->assertSame(NteAssetsFixture::SHA, $catalog['sha']);
    }

    public function testReadmeNotGlobalKeepsPreviousCatalog(): void
    {
        $fixture = new NteAssetsFixture(['README.md' => "# NTE_Assets\n\n> Version: 2.0.0 (CN)\n"]);
        $nteCatalog = $this->createCatalog(self::PREVIOUS_CATALOG);

        try {
            $this->createBuilder($fixture, $nteCatalog)->build();
            $this->fail('A non-Global datamine must abort the build.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Global', $e->getMessage());
        }

        $this->assertSame(['README.md'], $fixture->requestedRawPaths());
        $this->assertSame(self::PREVIOUS_CATALOG['sha'], $nteCatalog->getSha());
        $this->assertSame('Outdated Name', $nteCatalog->find('1042')['name']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function brokenRequiredFileProvider(): iterable
    {
        yield 'missing arc table' => ['DataTable/Fork/DT_ForkItemData.json', null];
        yield 'empty item table' => ['DataTable/Inventory/DT_ItemConfig.json', NteAssetsFixture::table('DT_ItemConfig', [])];
        yield 'character table without usable rows' => ['DataTable/Character/DT_Character.json', NteAssetsFixture::table('DT_Character', [
            '1099' => NteAssetsFixture::itemRow(NteAssetsFixture::text('/Game/Text/ST_Player.ST_Player', 'Role_Name_099'), 'ORANGE', '/Game/UI/UI_Icon/a.a'),
        ])];
        yield 'missing localization' => ['Localization/en/game.json', null];
        yield 'invalid JSON' => ['DataTable/Character/DT_Character.json', '{"broken'];
    }

    #[DataProvider('brokenRequiredFileProvider')]
    public function testBrokenRequiredFileKeepsPreviousCatalog(string $path, mixed $content): void
    {
        $fixture = new NteAssetsFixture([$path => $content]);
        $nteCatalog = $this->createCatalog(self::PREVIOUS_CATALOG);

        $this->expectException(\RuntimeException::class);

        try {
            $this->createBuilder($fixture, $nteCatalog)->build();
        } finally {
            $this->assertSame(self::PREVIOUS_CATALOG['sha'], $nteCatalog->getSha());
            $this->assertSame(self::PREVIOUS_CATALOG['items'], $nteCatalog->all()['items']);
        }
    }

    public function testMissingOptionalTablesAreSkipped(): void
    {
        $fixture = new NteAssetsFixture([
            'DataTable/Vehicle/DT_VehicleItemData.json' => null,
            'DataTable/Avarta/AvatarDataTable.json' => null,
            'DataTable/Fork/DT_ForkLotteryPoolData.json' => null,
        ]);

        $catalog = $this->createBuilder($fixture)->build();

        $this->assertArrayNotHasKey('vehicle002', $catalog['items']);
        $this->assertArrayHasKey('fork_twinbirds', $catalog['items']);
        $this->assertSame([], $catalog['avatars']);
        $this->assertArrayNotHasKey('forklottery_heiyu', $catalog['pools']);
        $this->assertSame('Foretold Finale', $catalog['pools']['lottery_heiyu']);
        $this->assertSame([], $catalog['banners']);
    }

    public function testMergeKeepsIdsMissingFromTheNewDatamine(): void
    {
        $nteCatalog = $this->createCatalog(self::PREVIOUS_CATALOG);

        $catalog = $this->createBuilder(new NteAssetsFixture(), $nteCatalog)->build();

        // Kept as stored: an entry built before the previews has no preview_path until the game lists it again
        $this->assertSame(self::PREVIOUS_CATALOG['items']['old_item'], $catalog['items']['old_item']);
        $this->assertSame('Blackbird', $catalog['items']['1042']['name']);
        $this->assertSame('S', $catalog['items']['1042']['rank']);
        $this->assertSame('UI/Appearance/Fashion/1024/YH_lihui_fashionshop_heiyu.png', $catalog['items']['1042']['preview_path']);
        $this->assertSame('UI_Icon/AvatarImage/256/old_256.png', $catalog['avatars']['old_avatar']);
        $this->assertSame('Old Board', $catalog['pools']['lottery_old']);
        $this->assertSame(NteAssetsFixture::SHA, $catalog['sha']);

        // Ended banners stay (with the fields added since), current ones are rebuilt
        $this->assertSame(
            self::PREVIOUS_CATALOG['banners']['lottery_old'] + ['up_source' => 'nte_assets', 'runs' => []],
            $catalog['banners']['lottery_old'],
        );
        $this->assertSame(
            ['id' => 'ForkLottery_HeiYu', 'category' => 'arc', 'name' => 'Foretold Special', 'up' => [], 'others' => [], 'up_guarantee' => null, 'up_source' => 'nte_assets', 'runs' => []],
            $catalog['banners']['forklottery_heiyu'],
        );

        // build() does not persist: the command saves the result
        $this->assertSame(self::PREVIOUS_CATALOG['sha'], $nteCatalog->getSha());
    }

    public function testBuildsArcBannersFromThePoolTable(): void
    {
        $fixture = self::bannerFixture();
        $catalog = $this->createBuilder($fixture, null, $this->createLogger())->build();

        // Rate-up from UpList (blank and non-string ids dropped), other S from the show rewards; its rate-up
        // Arc "Crime and Punishment" is the irminsul 1.4.1 Arc phase
        $this->assertSame([
            'id' => 'ForkLottery_HeiYu',
            'category' => NteCatalog::BANNER_ARC,
            'name' => 'Foretold Special',
            'up' => ['fork_twinbirds'],
            'others' => ['fork_Butterfly', 'fork_BlackBook'],
            'up_guarantee' => 80,
            'up_source' => NteCatalog::UP_SOURCE_NTE_ASSETS,
            'runs' => [self::RUNS['1.4.1']],
        ], $catalog['banners']['forklottery_heiyu']);

        // Without UpList the "IsUp" show rewards are the rate-up; no name, no positive guarantee and no run
        // (its rate-up Arc is not in the catalog)
        $this->assertSame([
            'id' => 'ForkLottery_Zhenhong',
            'category' => NteCatalog::BANNER_ARC,
            'name' => null,
            'up' => ['fork_LunarPhase'],
            'others' => ['fork_Butterfly'],
            'up_guarantee' => null,
            'up_source' => NteCatalog::UP_SOURCE_NTE_ASSETS,
            'runs' => [],
        ], $catalog['banners']['forklottery_zhenhong']);

        // Row "1" repeats the launch pool
        $this->assertArrayNotHasKey('1', $catalog['banners']);

        // Pools keep their shape: named Arc pools only
        $this->assertSame('Foretold Special', $catalog['pools']['forklottery_heiyu']);
        $this->assertArrayNotHasKey('forklottery_zhenhong', $catalog['pools']);
        $this->assertArrayNotHasKey('1', $catalog['pools']);

        // The pool table and the character table are downloaded once
        $downloads = array_count_values($fixture->requestedRawPaths());
        $this->assertSame(1, $downloads[self::POOL_TABLE]);
        $this->assertSame(1, $downloads['DataTable/Character/DT_Character.json']);

        $built = array_values(array_filter($this->logs, static fn (array $log): bool => $log['message'] === 'NTE catalog built'));
        $this->assertCount(1, $built);
        $this->assertSame(10, $built[0]['context']['banners']);
    }

    public function testBuildsBoardBannersWithTheirFeaturedCharacter(): void
    {
        $catalog = $this->createBuilder(self::bannerFixture(), null, $this->createLogger())->build();

        $boards = array_filter($catalog['banners'], static fn (array $banner): bool => $banner['category'] === NteCatalog::BANNER_BOARD);
        // Runs from the irminsul character phases featuring the board character
        $board = static fn (string $id, ?string $name, array $up, array $runs = []): array => [
            'id' => $id,
            'category' => NteCatalog::BANNER_BOARD,
            'name' => $name,
            'up' => $up,
            'others' => [],
            'up_guarantee' => null,
            'up_source' => NteCatalog::UP_SOURCE_NTE_ASSETS,
            'runs' => $runs,
        ];

        $this->assertSame([
            // Guarantee key without suffix; rerun in 1.3.1
            'lottery_nanali' => $board('Lottery_Nanali', null, ['1010'], [self::RUNS['1.0.1'], self::RUNS['1.3.1']]),
            'lottery_heiyu' => $board('Lottery_HeiYu', 'Foretold Finale', ['1042'], [self::RUNS['1.4.1']]),
            // "_Changzhu" names no featured character
            'lottery_permanent' => $board('Lottery_Permanent', 'Strange Encounters', []),
            // Only the pullable one of the three S "Lacrimosa" rows
            'lottery_anhunqu' => $board('Lottery_AnHunQu', 'Fading Reverie', ['1004'], [self::RUNS['1.1.1'], self::RUNS['1.4.1']]),
            // "_CanHong" key, the A character 1024 is also named "Zankou"
            'lottery_canhong' => $board('Lottery_Canhong', null, ['1036'], [self::RUNS['1.3.1']]),
            // Unknown and ambiguous names
            'lottery_kaesi' => $board('Lottery_Kaesi', null, []),
            'lottery_exe' => $board('Lottery_Exe', null, []),
            // No guarantee text
            'lottery_xun' => $board('Lottery_Xun', null, []),
        ], $boards);

        $warnings = array_values(array_map(
            static fn (array $log): array => $log['context'],
            array_filter($this->logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING && isset($log['context']['pool'])),
        ));
        $this->assertSame([
            ['pool' => 'Lottery_Kaesi', 'name' => 'Chaos', 'matches' => []],
            ['pool' => 'Lottery_Exe', 'name' => 'Exe', 'matches' => ['1074', '1075']],
            // A limited board without guarantee text is flagged; the standard board is not
            ['pool' => 'Lottery_Xun'],
        ], $warnings);
    }

    public function testMissingBoardTableLeavesOnlyArcBanners(): void
    {
        $catalog = $this->createBuilder(self::bannerFixture([self::BOARD_CELL_TABLE => null]), null, $this->createLogger())->build();

        $this->assertSame(['forklottery_heiyu', 'forklottery_zhenhong'], array_keys($catalog['banners']));
        $this->assertSame('Foretold Finale', $catalog['pools']['lottery_heiyu']);

        $skipped = array_column(array_filter($this->logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING && isset($log['context']['path'])), 'context');
        $this->assertSame([['path' => self::BOARD_CELL_TABLE, 'sha' => NteAssetsFixture::SHA]], $skipped);
    }

    public function testRealIrminsulPhasesGiveEveryBannerItsRuns(): void
    {
        $irminsul = new NteIrminsulFixture();
        $catalog = $this->createBuilder(new NteAssetsFixture(NteAssetsFixture::realBannerFiles()), null, $this->createLogger(), $irminsul)->build();
        $banners = $catalog['banners'];

        // Every phase maps by its featured S; a rerun phase (1.3.1 on) adds a run to two boards and two Arc pools
        $versions = [
            'nanali' => ['1.0.1', '1.3.1'],
            'xun' => ['1.0.2', '1.3.2'],
            'anhunqu' => ['1.1.1', '1.4.1'],
            'kaesi' => ['1.1.2', '1.5.2'],
            'zhenhong' => ['1.2.1', '1.5.1'],
            'yiluoyi' => ['1.2.2'],
            'canhong' => ['1.3.1'],
            'lingke' => ['1.3.2'],
            'heiyu' => ['1.4.1'],
            'mingyin' => ['1.4.2'],
            'yeermu' => ['1.5.1'],
            'exe' => ['1.5.2'],
        ];
        $expected = ['lottery_permanent' => []];
        foreach ($versions as $suffix => $phaseVersions) {
            $runs = array_map(static fn (string $version): array => self::RUNS[$version], $phaseVersions);
            $expected['forklottery_' . $suffix] = $runs;
            $expected['lottery_' . $suffix] = $runs;
        }
        $actual = array_map(static fn (array $banner): array => $banner['runs'], $banners);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(34, array_sum(array_map('count', $actual)));

        // NTE_Assets keeps the featured items
        $this->assertSame(['fork_TigerTally'], $banners['forklottery_nanali']['up']);
        $this->assertSame(['1010'], $banners['lottery_nanali']['up']);
        foreach ($banners as $banner) {
            $this->assertSame(NteCatalog::UP_SOURCE_NTE_ASSETS, $banner['up_source']);
        }

        $this->assertSame([], array_values(array_filter($this->logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING)), 'Every rate-up is matched');
        $this->assertSame(['phases' => 24, 'mapped_phases' => 24, 'banners' => 24, 'runs' => 34], $this->logContext('NTE banner runs read from irminsul.gg'));
        $this->assertSame(34, $this->logContext('NTE catalog built')['banner_runs']);
        // The weapon items are not needed: Arcs are matched against the catalog
        $this->assertSame([NteIrminsulFixture::CHARACTERS, NteIrminsulFixture::BANNER_CHARACTERS, NteIrminsulFixture::BANNER_WEAPONS], $irminsul->requestedPaths);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function irminsulFailureProvider(): iterable
    {
        yield 'every file down' => [NteIrminsulFixture::down()->files];
        // Character phases readable: still nothing is taken from irminsul
        yield 'Arc phases down' => [[NteIrminsulFixture::BANNER_WEAPONS => null]];
        yield 'invalid JSON' => [[NteIrminsulFixture::BANNER_CHARACTERS => '{"broken']];
        yield 'no usable phase' => [[NteIrminsulFixture::BANNER_WEAPONS => [['id' => 1, 'version' => '1.0.1', 'rateUps' => ['Ready-Ready']]]]];
    }

    /**
     * @param array<string, mixed> $irminsulFiles
     */
    #[DataProvider('irminsulFailureProvider')]
    public function testIrminsulFailureKeepsThePreviousRuns(array $irminsulFiles): void
    {
        $previous = self::PREVIOUS_CATALOG;
        $previous['banners']['lottery_heiyu'] = ['id' => 'Lottery_HeiYu', 'category' => 'board', 'name' => 'Foretold Finale', 'up' => ['1042'], 'others' => [], 'up_guarantee' => null, 'up_source' => 'nte_assets', 'runs' => [self::RUNS['1.4.1']]];
        // Ended banner, no longer in the datamine
        $previous['banners']['forklottery_old'] = ['id' => 'ForkLottery_Old', 'category' => 'arc', 'name' => 'Old Special', 'up' => ['fork_old'], 'others' => [], 'up_guarantee' => 80, 'up_source' => 'nte_assets', 'runs' => [self::RUNS['1.0.1']]];

        $catalog = $this->createBuilder(
            new NteAssetsFixture(NteAssetsFixture::realBannerFiles()),
            $this->createCatalog($previous),
            $this->createLogger(),
            new NteIrminsulFixture($irminsulFiles),
        )->build();
        $banners = $catalog['banners'];

        // The build goes on with the datamine
        $this->assertSame(NteAssetsFixture::SHA, $catalog['sha']);
        $this->assertSame(['fork_TigerTally'], $banners['forklottery_nanali']['up']);

        // Runs of the previous catalog, banner by banner, none from irminsul
        $this->assertSame([self::RUNS['1.4.1']], $banners['lottery_heiyu']['runs']);
        $this->assertSame([self::RUNS['1.0.1']], $banners['forklottery_old']['runs']);
        $this->assertSame([], $banners['lottery_nanali']['runs']);
        $this->assertSame([], $banners['forklottery_heiyu']['runs']);
        // An ended banner of a catalog built before the runs reads as without them
        $this->assertSame(['up_source' => 'nte_assets', 'runs' => []], array_intersect_key($banners['lottery_old'], ['up_source' => 0, 'runs' => 0]));

        // One warning for the build (the phase normalizer may add its own about malformed entries), nothing mapped
        $warnings = array_values(array_filter($this->logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING && !str_starts_with($log['message'], 'irminsul banner entry skipped')));
        $this->assertCount(1, $warnings);
        $this->assertSame('irminsul.gg banners unavailable, NTE banner runs kept from the previous catalog', $warnings[0]['message']);
        $this->assertNotSame('', $warnings[0]['context']['error']);
    }

    public function testRunsAreRecomputedFromTheWholeHistory(): void
    {
        $previous = self::PREVIOUS_CATALOG;
        // Stale run, replaced rather than completed
        $previous['banners']['lottery_heiyu'] = ['id' => 'Lottery_HeiYu', 'category' => 'board', 'name' => 'Foretold Finale', 'up' => ['1042'], 'others' => [], 'up_guarantee' => null, 'runs' => [['start_at' => '2026-01-01 00:00:00', 'end_at' => '2026-01-02 00:00:00']]];
        // Ended board no longer in the datamine, featuring the S character Sakiri (1003)
        $previous['banners']['lottery_old']['up'] = ['1003'];
        $nteCatalog = $this->createCatalog($previous);

        $files = (new NteIrminsulFixture())->files;
        $files[NteIrminsulFixture::BANNER_CHARACTERS][] = NteIrminsulFixture::banner(10099, '0.9.1', '2026-03-01 11:00:00 UTC+8', '2026-03-15 05:59:59 UTC+8', ['Sakiri']);
        $irminsul = new NteIrminsulFixture($files);
        $fixture = new NteAssetsFixture(NteAssetsFixture::realBannerFiles());

        $first = $this->createBuilder($fixture, $nteCatalog, null, $irminsul)->build();
        $nteCatalog->save($first);
        $second = $this->createBuilder($fixture, $nteCatalog, null, $irminsul)->build();

        foreach ([$first, $second] as $catalog) {
            $this->assertSame([self::RUNS['1.4.1']], $catalog['banners']['lottery_heiyu']['runs']);
            $this->assertSame([['start_at' => '2026-03-01 03:00:00', 'end_at' => '2026-03-14 21:59:59']], $catalog['banners']['lottery_old']['runs']);
            $this->assertSame([self::RUNS['1.0.1'], self::RUNS['1.3.1']], $catalog['banners']['lottery_nanali']['runs']);
        }
        $this->assertSame($first['banners'], $second['banners']);
    }

    public function testUnmatchedRateUpsAreLoggedAndSkipped(): void
    {
        $irminsul = new NteIrminsulFixture([
            NteIrminsulFixture::CHARACTERS => array_merge((new NteIrminsulFixture())->files[NteIrminsulFixture::CHARACTERS], [
                NteIrminsulFixture::character(1100, 'Twin', 5),
                NteIrminsulFixture::character(1101, 'Twin', 5),
            ]),
            // A character: skipped without warning; unknown, ambiguous, or featured by no board: warning
            NteIrminsulFixture::BANNER_CHARACTERS => [
                NteIrminsulFixture::banner(10008, '1.4.1', '2026-09-30 11:00:00 UTC+8', '2026-10-21 05:59:59 UTC+8', ['Blackbird', 'Mint', 'Nobody', 'Twin', 'Sakiri']),
            ],
            // Arc names are case-insensitive; unknown and non-S Arcs: warning
            NteIrminsulFixture::BANNER_WEAPONS => [
                NteIrminsulFixture::banner(20008, '1.4.1', '2026-09-30 11:00:00 UTC+8', '2026-10-21 05:59:59 UTC+8', ['crime AND punishment', 'Unknown Arc', 'Be Happy']),
            ],
        ]);

        $catalog = $this->createBuilder(new NteAssetsFixture(NteAssetsFixture::realBannerFiles()), null, $this->createLogger(), $irminsul)->build();

        $withRuns = array_filter($catalog['banners'], static fn (array $banner): bool => $banner['runs'] !== []);
        $this->assertSame(['forklottery_heiyu', 'lottery_heiyu'], array_keys($withRuns));
        $this->assertSame([self::RUNS['1.4.1']], $withRuns['forklottery_heiyu']['runs']);
        $this->assertSame([self::RUNS['1.4.1']], $withRuns['lottery_heiyu']['runs']);

        $warnings = array_values(array_map(
            static fn (array $log): array => [$log['message'], $log['context']],
            array_filter($this->logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING),
        ));
        $board = ['category' => 'board', 'version' => '1.4.1'];
        $arc = ['category' => 'arc', 'version' => '1.4.1'];
        $this->assertSame([
            ['irminsul.gg NTE rate-up character not resolved, no run added', $board + ['name' => 'Nobody', 'matches' => []]],
            ['irminsul.gg NTE rate-up character not resolved, no run added', $board + ['name' => 'Twin', 'matches' => ['1100', '1101']]],
            ['irminsul.gg NTE rate-up featured by no catalog banner, no run added', $board + ['name' => 'Sakiri', 'item' => '1003']],
            ['irminsul.gg NTE rate-up Arc not resolved, no run added', $arc + ['name' => 'Unknown Arc', 'matches' => []]],
            ['irminsul.gg NTE rate-up Arc not resolved, no run added', $arc + ['name' => 'Be Happy', 'matches' => []]],
        ], $warnings);
    }

    public function testPhaseNormalizerLogsOnTheBuilderChannel(): void
    {
        $files = (new NteIrminsulFixture())->files;
        $index = count($files[NteIrminsulFixture::BANNER_WEAPONS]);
        // No start nor end: skipped by the phase normalizer
        $files[NteIrminsulFixture::BANNER_WEAPONS][] = ['id' => 20099, 'version' => '1.5.2', 'rateUps' => ['Ready-Ready']];

        $this->createBuilder(new NteAssetsFixture(NteAssetsFixture::realBannerFiles()), null, $this->createLogger(), new NteIrminsulFixture($files))->build();

        $this->assertSame(
            ['category' => NteCatalog::BANNER_ARC, 'index' => $index, 'id' => 20099, 'version' => '1.5.2'],
            $this->logContext('irminsul banner entry skipped: malformed'),
        );
        // The other phases are mapped as usual
        $this->assertSame(34, $this->logContext('NTE banner runs read from irminsul.gg')['runs']);
    }

    /**
     * Context of the single log entry with the given message.
     *
     * @return array<string, mixed>
     */
    private function logContext(string $message): array
    {
        $entries = array_values(array_filter($this->logs, static fn (array $log): bool => $log['message'] === $message));
        $this->assertCount(1, $entries, $message);

        return $entries[0]['context'];
    }

    /**
     * Default repository plus Arc pool rows, board cells, guarantee texts and pullable S characters.
     *
     * @param array<string, mixed> $files repository path => content, replacing the banner files
     */
    private static function bannerFixture(array $files = []): NteAssetsFixture
    {
        $defaults = NteAssetsFixture::defaultFiles();

        $localization = $defaults['Localization/en/game.json'];
        $localization['ST_Player'] += [
            'Role_Name_004' => 'Lacrimosa',
            'Role_Name_010' => 'Nanally',
            'Role_Name_036' => 'Zankou',
            'Role_Name_074' => 'Exe',
        ];
        // Blank in an earlier namespace: the first non-blank text wins
        $localization['ST_Other'][self::FEATURED_KEY . '_HeiYu'] = ' ';
        $localization['ST_Ui'] += [
            self::FEATURED_KEY => self::featuredText('Nanally'),
            self::FEATURED_KEY . '_HeiYu' => self::featuredText('Blackbird'),
            self::FEATURED_KEY . '_AnHunQu' => self::featuredText('Lacrimosa'),
            self::FEATURED_KEY . '_CanHong' => self::featuredText('Zankou'),
            self::FEATURED_KEY . '_Kaesi' => 'Get <Orange>S-Class</> <Red>Chaos</> after roll ×<Blue>{0}</>',
            self::FEATURED_KEY . '_Exe' => self::featuredText('Exe'),
            self::FEATURED_KEY . '_Changzhu' => 'Guaranteed <Orange>S-Class</> Character within roll ×<Blue>{0}</>',
        ];
        // The same text repeated in another namespace
        $localization['ST_UI_hpy'] = [self::FEATURED_KEY . '_HeiYu' => self::featuredText('Blackbird')];

        $characters = $defaults['DataTable/Character/DT_Character.json'][0]['Rows'];
        $characters['1042']['bIsInvite'] = true;
        // A character: never featured
        $characters['1024']['bIsInvite'] = true;
        $characters += [
            '1010' => self::character('Role_Name_010', true),
            '1036' => self::character('Role_Name_036', true),
            // Three S rows named "Lacrimosa", only one can be pulled
            '1004' => self::character('Role_Name_004', true),
            '1056' => self::character('Role_Name_004', false),
            '1091' => self::character('Role_Name_004', null),
            // Two pullable S characters with the same name
            '1074' => self::character('Role_Name_074', true),
            '1075' => self::character('Role_Name_074', true),
        ];

        return new NteAssetsFixture(array_replace([
            'Localization/en/game.json' => $localization,
            'DataTable/Character/DT_Character.json' => NteAssetsFixture::table('DT_Character', $characters),
            self::POOL_TABLE => NteAssetsFixture::table('DT_ForkLotteryPoolData', [
                'ForkLottery_HeiYu' => [
                    'Name' => NteAssetsFixture::text('/Game/Text/ST_Ui.ST_Ui', 'ui_forkshop_24', 'Miracle Box'),
                    'ShowText1' => NteAssetsFixture::text('/Game/Text/ST_Ui.ST_Ui', 'ui_forklottery_up_heiyu_01'),
                    'UpList' => ['fork_twinbirds', '', 7],
                    'UpGuaranteeCnt' => 80,
                    'ShowRewards' => [
                        ['ItemID' => 'fork_twinbirds', 'IsUp' => true],
                        ['ItemID' => 'fork_Butterfly', 'IsUp' => false],
                        ['ItemID' => 'fork_BlackBook', 'IsUp' => false],
                        ['ItemID' => '', 'IsUp' => false],
                        ['ItemID' => 'fork_NoFlag'],
                    ],
                ],
                'ForkLottery_Zhenhong' => [
                    'UpGuaranteeCnt' => 0,
                    'ShowRewards' => [
                        ['ItemID' => 'fork_LunarPhase', 'IsUp' => true],
                        ['ItemID' => 'fork_Butterfly', 'IsUp' => false],
                    ],
                ],
                '1' => [
                    'Name' => NteAssetsFixture::text('/Game/Text/ST_Ui.ST_Ui', 'ui_forkshop_24', 'Miracle Box'),
                    'UpList' => ['fork_TigerTally'],
                    'UpGuaranteeCnt' => 80,
                    'ShowRewards' => [['ItemID' => 'fork_TigerTally', 'IsUp' => true]],
                ],
            ]),
            self::BOARD_CELL_TABLE => NteAssetsFixture::table('DT_MonopolyCellDataTable', [
                '9' => ['CellID' => '9', 'PoolDropDatas' => [
                    NteAssetsFixture::poolDrop('Lottery_Nanali'),
                    NteAssetsFixture::poolDrop('Lottery_HeiYu'),
                    NteAssetsFixture::poolDrop('Lottery_Permanent'),
                    NteAssetsFixture::poolDrop('Lottery_AnHunQu'),
                ]],
                '10' => ['CellID' => '10', 'PoolDropDatas' => [
                    NteAssetsFixture::poolDrop('Lottery_Nanali'),
                    NteAssetsFixture::poolDrop('Lottery_Canhong'),
                    NteAssetsFixture::poolDrop('Lottery_Kaesi'),
                    NteAssetsFixture::poolDrop('Lottery_Exe'),
                    NteAssetsFixture::poolDrop('Lottery_Xun'),
                    ['Key' => ' '],
                    'broken',
                ]],
                '11' => ['CellID' => '11', 'PoolDropDatas' => []],
                '12' => ['CellID' => '12'],
            ]),
        ], $files));
    }

    /**
     * Contexts of the "optional table missing" warnings about a table.
     *
     * @return list<array<string, mixed>>
     */
    private function skippedTableWarnings(string $path): array
    {
        return array_values(array_column(array_filter(
            $this->logs,
            static fn (array $log): bool => $log['level'] === LogLevel::WARNING && ($log['context']['path'] ?? null) === $path,
        ), 'context'));
    }

    private static function featuredText(string $name): string
    {
        return sprintf('Guaranteed <Orange>S-Class</> Character <Red>%s</> within roll ×<Blue>{0}</>', $name);
    }

    /**
     * @return array<string, mixed>
     */
    private static function character(string $nameKey, ?bool $invite): array
    {
        $row = NteAssetsFixture::itemRow(
            NteAssetsFixture::text('/Game/Text/ST_Player.ST_Player', $nameKey),
            'ORANGE',
            sprintf('/Game/UI/UI_Icon/AvatarImage/200/%1$s.%1$s', strtolower($nameKey)),
            'EItemType::ITEM_TYPE_CHARACTER',
        );
        if ($invite !== null) {
            $row['bIsInvite'] = $invite;
        }

        return $row;
    }

    private function createLogger(): LoggerInterface
    {
        return new class($this->logs) extends AbstractLogger {
            /**
             * @param list<array{level: string, message: string, context: array<string, mixed>}> $logs
             */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function createBuilder(NteAssetsFixture $fixture, ?NteCatalog $catalog = null, ?LoggerInterface $logger = null, ?NteIrminsulFixture $irminsul = null): NteCatalogBuilder
    {
        return new NteCatalogBuilder(
            $fixture->client(),
            $catalog ?? $this->createCatalog(),
            ($irminsul ?? new NteIrminsulFixture())->irminsulClient(),
            // Silent default channel: the normalizer logs reach $logger only through the builder
            new IrminsulPhaseNormalizer(new NullLogger()),
            $logger ?? new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    private function createCatalog(?array $stored = null): NteCatalog
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        if ($stored !== null) {
            $catalog->save($stored);
        }

        return $catalog;
    }
}
