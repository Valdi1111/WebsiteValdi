<?php

namespace App\Tests\PerfectworldBundle\Catalog;

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

    private const string FEATURED_KEY = 'BPUI_LotteryModuleEntrance_BigGurantten';
    private const string POOL_TABLE = 'DataTable/Fork/DT_ForkLotteryPoolData.json';
    private const string BOARD_CELL_TABLE = 'DataTable/Monopoly/DT_MonopolyCellDataTable.json';

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
        $this->assertSame(['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'UI_Icon/AvatarImage/200/player_heiyu.png'], $items['1042']);
        $this->assertSame('Zankou', $items['1024']['name']);
        $this->assertSame('A', $items['1024']['rank']);
        $this->assertSame('Embedded Name', $items['1098']['name']);
        $this->assertArrayNotHasKey('1099', $items);

        // Arcs: icon file name not derived from the id, key found outside its namespace
        $this->assertSame(['id' => 'fork_twinbirds', 'name' => 'Crime and Punishment', 'rank' => 'S', 'type' => 'arc', 'icon_path' => 'UI_Icon/Fork/fork_twinbirds_256.png'], $items['fork_twinbirds']);
        $this->assertSame(['id' => 'fork_vine', 'name' => 'Be Happy', 'rank' => 'B', 'type' => 'arc', 'icon_path' => 'UI_Icon/Fork/fork_HugVine_256.png'], $items['fork_vine']);

        // Items: path kept with its case, object name ignored
        $this->assertSame(['id' => 'DiceNormal', 'name' => 'Fabricated Dice', 'rank' => 'S', 'type' => 'item', 'icon_path' => 'UI_Icon/Item/DiceNormal.png'], $items['dicenormal']);
        $this->assertSame('item', $items['annulith']['type']);
        $this->assertNull($items['green_item']['rank']);
        $this->assertNull($items['bad_icon']['icon_path']);

        // Cosmetics: Fashion_ prefix, cosmetic item types, vehicle and appearance tables
        $this->assertSame(['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI/Appearance/Glider/Fashion_Glide_1075.png'], $items['fashion_glide_1075']);
        $this->assertSame('cosmetic', $items['glide_1']['type']);
        $this->assertSame(['id' => 'vehicle002', 'name' => 'Griffin', 'rank' => 'S', 'type' => 'cosmetic', 'icon_path' => 'UI_Icon/Item/YH_UI_CarCard1.png'], $items['vehicle002']);
        $this->assertSame('UI_Icon/AvatarImage/256/player_sakiri_256.png', $items['fashion_1003_0']['icon_path']);

        // DT_ItemConfig sets type and name, DT_AppearanceData fills the missing rank and icon
        $this->assertSame(['id' => 'Fashion_1042_1', 'name' => 'Wandering Stars', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI_Icon/AvatarImage/256/player_heiyu1_256.png'], $items['fashion_1042_1']);

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

        $this->assertSame(self::PREVIOUS_CATALOG['items']['old_item'], $catalog['items']['old_item']);
        $this->assertSame('Blackbird', $catalog['items']['1042']['name']);
        $this->assertSame('S', $catalog['items']['1042']['rank']);
        $this->assertSame('UI_Icon/AvatarImage/256/old_256.png', $catalog['avatars']['old_avatar']);
        $this->assertSame('Old Board', $catalog['pools']['lottery_old']);
        $this->assertSame(NteAssetsFixture::SHA, $catalog['sha']);

        // Ended banners stay, current ones are rebuilt
        $this->assertSame(self::PREVIOUS_CATALOG['banners']['lottery_old'], $catalog['banners']['lottery_old']);
        $this->assertSame(
            ['id' => 'ForkLottery_HeiYu', 'category' => 'arc', 'name' => 'Foretold Special', 'up' => [], 'others' => [], 'up_guarantee' => null],
            $catalog['banners']['forklottery_heiyu'],
        );

        // build() does not persist: the command saves the result
        $this->assertSame(self::PREVIOUS_CATALOG['sha'], $nteCatalog->getSha());
    }

    public function testBuildsArcBannersFromThePoolTable(): void
    {
        $fixture = self::bannerFixture();
        $catalog = $this->createBuilder($fixture, null, $this->createLogger())->build();

        // Rate-up from UpList (blank and non-string ids dropped), other S from the show rewards
        $this->assertSame([
            'id' => 'ForkLottery_HeiYu',
            'category' => NteCatalog::BANNER_ARC,
            'name' => 'Foretold Special',
            'up' => ['fork_twinbirds'],
            'others' => ['fork_Butterfly', 'fork_BlackBook'],
            'up_guarantee' => 80,
        ], $catalog['banners']['forklottery_heiyu']);

        // Without UpList the "IsUp" show rewards are the rate-up; no name and no positive guarantee
        $this->assertSame([
            'id' => 'ForkLottery_Zhenhong',
            'category' => NteCatalog::BANNER_ARC,
            'name' => null,
            'up' => ['fork_LunarPhase'],
            'others' => ['fork_Butterfly'],
            'up_guarantee' => null,
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
        $board = static fn (string $id, ?string $name, array $up): array => [
            'id' => $id,
            'category' => NteCatalog::BANNER_BOARD,
            'name' => $name,
            'up' => $up,
            'others' => [],
            'up_guarantee' => null,
        ];

        $this->assertSame([
            // Guarantee key without suffix
            'lottery_nanali' => $board('Lottery_Nanali', null, ['1010']),
            'lottery_heiyu' => $board('Lottery_HeiYu', 'Foretold Finale', ['1042']),
            // "_Changzhu" names no featured character
            'lottery_permanent' => $board('Lottery_Permanent', 'Strange Encounters', []),
            // Only the pullable one of the three S "Lacrimosa" rows
            'lottery_anhunqu' => $board('Lottery_AnHunQu', 'Fading Reverie', ['1004']),
            // "_CanHong" key, the A character 1024 is also named "Zankou"
            'lottery_canhong' => $board('Lottery_Canhong', null, ['1036']),
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

        $skipped = array_column(array_filter($this->logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING), 'context');
        $this->assertSame([['path' => self::BOARD_CELL_TABLE, 'sha' => NteAssetsFixture::SHA]], $skipped);
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
                    self::poolDrop('Lottery_Nanali'),
                    self::poolDrop('Lottery_HeiYu'),
                    self::poolDrop('Lottery_Permanent'),
                    self::poolDrop('Lottery_AnHunQu'),
                ]],
                '10' => ['CellID' => '10', 'PoolDropDatas' => [
                    self::poolDrop('Lottery_Nanali'),
                    self::poolDrop('Lottery_Canhong'),
                    self::poolDrop('Lottery_Kaesi'),
                    self::poolDrop('Lottery_Exe'),
                    self::poolDrop('Lottery_Xun'),
                    ['Key' => ' '],
                    'broken',
                ]],
                '11' => ['CellID' => '11', 'PoolDropDatas' => []],
                '12' => ['CellID' => '12'],
            ]),
        ], $files));
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

    /**
     * @return array{Key: string, Value: array<string, mixed>}
     */
    private static function poolDrop(string $poolId): array
    {
        return ['Key' => $poolId, 'Value' => ['MapDropDatas' => [['Key' => 'EMonopolyMapType::EMMT_Base_Map', 'Value' => 'Board_Chess_LessMiDie']]]];
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

    private function createBuilder(NteAssetsFixture $fixture, ?NteCatalog $catalog = null, ?LoggerInterface $logger = null): NteCatalogBuilder
    {
        return new NteCatalogBuilder($fixture->client(), $catalog ?? $this->createCatalog(), $logger ?? new NullLogger());
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
