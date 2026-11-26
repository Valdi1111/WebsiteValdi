<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use App\GryphlineBundle\Service\Catalog\AkeCatalogBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AkeCatalogBuilderTest extends TestCase
{
    /** UTC runs of the default irminsul.gg banners: 1.0.3 (12:00 UTC-5 to 11:59:59 UTC-5) and 1.7.2 RE-Factor (11:00 UTC+8) */
    private const array RUN_1_0_3_CHAR = ['start_at' => '2026-02-24 17:00:00', 'end_at' => '2026-03-11 16:59:59'];
    private const array RUN_1_0_3_WEAPON = ['start_at' => '2026-02-24 17:00:00', 'end_at' => '2026-04-16 16:59:59'];
    private const array RUN_1_7_2 = ['start_at' => '2026-11-25 03:00:00', 'end_at' => '2026-12-16 16:59:59'];

    private const array CHAR_POOLS = [
        'beginner' => ['id' => 'beginner', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_BEGINNER, 'up' => [], 'up_source' => 'akedata', 'runs' => []],
        'joint_1_2_2' => ['id' => 'joint_1_2_2', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_JOINT, 'up' => ['chr_0016_laevat', 'chr_0013_aglina'], 'up_source' => 'akedata', 'runs' => []],
        'rerun_chr_aglina' => ['id' => 'rerun_chr_aglina', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_RERUN, 'up' => ['chr_0013_aglina'], 'up_source' => 'irminsul', 'runs' => [self::RUN_1_7_2]],
        'special_1_0_1' => ['id' => 'special_1_0_1', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_SPECIAL, 'up' => ['chr_0016_laevat'], 'up_source' => 'akedata', 'runs' => []],
        'special_1_0_3' => ['id' => 'special_1_0_3', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_SPECIAL, 'up' => ['chr_0013_aglina'], 'up_source' => 'irminsul', 'runs' => [self::RUN_1_0_3_CHAR]],
        'standard' => ['id' => 'standard', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_STANDARD, 'up' => [], 'up_source' => 'akedata', 'runs' => []],
    ];

    private const array WEAPON_POOLS = [
        'rerun_wpn_aglina' => ['id' => 'rerun_wpn_aglina', 'category' => 'weapon', 'type' => AkeCatalog::WEAPON_POOL_TYPE_RERUN, 'up' => ['wpn_funnel_0011'], 'up_source' => 'irminsul', 'runs' => [self::RUN_1_7_2]],
        'weaponbox_constant_1' => ['id' => 'weaponbox_constant_1', 'category' => 'weapon', 'type' => AkeCatalog::WEAPON_POOL_TYPE_NORMAL, 'up' => ['wpn_claym_0013'], 'up_source' => 'akedata', 'runs' => []],
        'weponbox_1_0_3' => ['id' => 'weponbox_1_0_3', 'category' => 'weapon', 'type' => AkeCatalog::WEAPON_POOL_TYPE_NORMAL, 'up' => ['wpn_funnel_0011'], 'up_source' => 'irminsul', 'runs' => [self::RUN_1_0_3_WEAPON]],
    ];

    public function testBuildsCompactCatalogOfTheLatestVersion(): void
    {
        $fixture = new AkeDataFixture();
        $irminsul = new EndfieldIrminsulFixture();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $catalog = $fixture->builder($logger, $irminsul)->build();

        $this->assertSame(AkeDataFixture::LATEST, $catalog['version']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $catalog['built_at']));

        // The item table is read (and reduced) before the big text table is downloaded, the pool tables last
        $this->assertSame([
            'manifest.json',
            AkeDataFixture::LATEST_PATH . '/ItemTable.json',
            AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json',
            AkeDataFixture::LATEST_PATH . '/' . AkeDataFixture::CHAR_POOL_TABLE,
            AkeDataFixture::LATEST_PATH . '/' . AkeDataFixture::WEAPON_POOL_TABLE,
        ], $fixture->requestedPaths);

        $this->assertSame([
            'chr_0002_endminm' => ['id' => 'chr_0002_endminm', 'name' => 'The Endministrator', 'rarity' => 6, 'type' => 'char'],
            'chr_0005_chen' => ['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 5, 'type' => 'char'],
            'chr_0009_azrila' => ['id' => 'chr_0009_azrila', 'name' => 'Ember', 'rarity' => 6, 'type' => 'char'],
            'chr_0013_aglina' => ['id' => 'chr_0013_aglina', 'name' => 'Gilberta', 'rarity' => 6, 'type' => 'char'],
            'wpn_claym_0003' => ['id' => 'wpn_claym_0003', 'name' => 'Industry 0.1', 'rarity' => 4, 'type' => 'weapon'],
            'wpn_funnel_0011' => ['id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'rarity' => 6, 'type' => 'weapon'],
        ], $catalog['items']);

        // Both tables merged and sorted by pool id; the row without an integer type is skipped
        $expectedPools = self::CHAR_POOLS + self::WEAPON_POOLS;
        ksort($expectedPools, \SORT_STRING);
        $this->assertSame($expectedPools, $catalog['pools']);
        $this->assertArrayNotHasKey('special_9_9_9', $catalog['pools']);

        $this->assertEqualsCanonicalizing([
            EndfieldIrminsulFixture::CHARACTERS,
            EndfieldIrminsulFixture::WEAPONS,
            EndfieldIrminsulFixture::BANNER_CHARACTERS,
            EndfieldIrminsulFixture::BANNER_WEAPONS,
        ], $irminsul->requestedPaths);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function brokenPoolTableProvider(): iterable
    {
        yield 'char table not found' => [AkeDataFixture::CHAR_POOL_TABLE, null, 'char'];
        yield 'invalid weapon table' => [AkeDataFixture::WEAPON_POOL_TABLE, '{"broken', 'weapon'];
        yield 'char table without typed pool' => [AkeDataFixture::CHAR_POOL_TABLE, ['standard' => ['id' => 'standard', 'upCharIds' => []]], 'char'];
    }

    #[DataProvider('brokenPoolTableProvider')]
    public function testBrokenPoolTableStillBuildsTheItemsWithAWarning(string $table, mixed $content, string $emptyCategory): void
    {
        $fixture = new AkeDataFixture([AkeDataFixture::LATEST_PATH . '/' . $table => $content]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->anything(),
            $this->callback(static fn (array $context): bool => $context['table'] === $table && $context['version'] === AkeDataFixture::LATEST),
        );

        // The irminsul.gg banners of the category without pools are left out without another warning
        $catalog = $fixture->builder($logger)->build();

        $this->assertSame(AkeDataFixture::LATEST, $catalog['version']);
        $this->assertCount(6, $catalog['items']);
        $this->assertSame(
            $emptyCategory === 'char' ? self::WEAPON_POOLS : self::CHAR_POOLS,
            $catalog['pools'],
        );
    }

    public function testKeepsOnlyOperatorsAndWeaponsWithAnEnglishName(): void
    {
        $items = $this->createBuilder(new AkeDataFixture())->build()['items'];

        // Other item kinds resolve too, but are not operators or weapons
        $this->assertArrayNotHasKey('achv_adv_tundra_box_1', $items);
        $this->assertArrayNotHasKey('item_gold', $items);

        // Text id missing from the English table, blank text, no text id
        $this->assertArrayNotHasKey('wpn_pistol_0008', $items);
        $this->assertArrayNotHasKey('chr_0098_blank', $items);
        $this->assertArrayNotHasKey('chr_0099_noname', $items);
    }

    public function testRequestedVersionUsesItsTableCfgPath(): void
    {
        $fixture = new AkeDataFixture();
        $catalog = $this->createBuilder($fixture)->build(' ' . AkeDataFixture::OLDER . ' ');

        $this->assertSame(AkeDataFixture::OLDER, $catalog['version']);
        $this->assertSame(['chr_0005_chen'], array_keys($catalog['items']));
        $this->assertSame(AkeDataFixture::OLDER_PATH . '/ItemTable.json', $fixture->requestedPaths[1]);
    }

    public function testCatalogWithoutResolvedNamesThrows(): void
    {
        $fixture = new AkeDataFixture([AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json' => ['42' => 'T-Creds']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No operator or weapon name could be resolved');

        $this->createBuilder($fixture)->build();
    }

    public function testItemTableWithoutOperatorsOrWeaponsThrowsBeforeTheTextTable(): void
    {
        $fixture = new AkeDataFixture([AkeDataFixture::LATEST_PATH . '/ItemTable.json' => [
            'item_gold' => AkeDataFixture::itemRow('item_gold', 42, 3, 1),
        ]]);

        try {
            $this->createBuilder($fixture)->build();
            $this->fail('An item table without operators or weapons must abort the build.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has no operator or weapon', $e->getMessage());
        }

        $this->assertNotContains(AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json', $fixture->requestedPaths);
    }

    public function testUnknownVersionThrowsBeforeAnyTable(): void
    {
        $fixture = new AkeDataFixture();

        try {
            $this->createBuilder($fixture)->build('9.9.9@1-1');
            $this->fail('An unknown manifest version must abort the build.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no version "9.9.9@1-1"', $e->getMessage());
        }

        $this->assertSame(['manifest.json'], $fixture->requestedPaths);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function brokenSourceProvider(): iterable
    {
        $manifest = AkeDataFixture::manifest();

        yield 'manifest not found' => ['manifest.json', null, 'manifest.json could not be read'];
        yield 'manifest without latest' => ['manifest.json', ['latest' => null] + $manifest, 'no latest version'];
        yield 'unsafe table path' => ['manifest.json', ['versions' => [['id' => AkeDataFixture::LATEST, 'tableCfgPath' => 'public/../../etc']]] + $manifest, 'no usable tableCfgPath'];
        yield 'missing table path' => ['manifest.json', ['versions' => [['id' => AkeDataFixture::LATEST]]] + $manifest, 'no usable tableCfgPath'];
        yield 'item table not found' => [AkeDataFixture::LATEST_PATH . '/ItemTable.json', null, 'ItemTable.json could not be read'];
        yield 'invalid text table' => [AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json', '{"broken', 'I18nTextTable_EN.json could not be read'];
    }

    #[DataProvider('brokenSourceProvider')]
    public function testBrokenSourceThrows(string $path, mixed $content, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->createBuilder(new AkeDataFixture([$path => $content]))->build();
    }

    public function testMapsEveryRealIrminsulBannerToItsPool(): void
    {
        $logger = self::recordingLogger();
        $pools = new AkeDataFixture(AkeDataFixture::realFiles())->builder($logger, EndfieldIrminsulFixture::real())->build()['pools'];

        // 19 operator and 19 weapon banners: one run each, weapon 1.2.2 on two pools
        $runs = array_map(static fn (array $pool): int => count($pool['runs']), $pools);
        $this->assertSame(39, array_sum($runs));
        $this->assertSame([], array_keys(array_filter($runs, static fn (int $count): bool => $count > 1)));

        // Every limited pool has its run; the standard, beginner and permanent weapon pools have none
        $withoutRun = array_keys(array_filter($runs, static fn (int $count): bool => $count === 0));
        sort($withoutRun);
        $this->assertSame(['beginner', 'standard', 'weaponbox_constant_1', 'weaponbox_constant_2', 'weaponbox_constant_3', 'weaponbox_constant_4', 'weaponbox_constant_5'], $withoutRun);
        foreach ($pools as $poolId => $pool) {
            $this->assertSame($pool['runs'] === [] ? 'akedata' : 'irminsul', $pool['up_source'], $poolId);
        }

        // By featured items: the pool ids of 1.0.2 and 1.0.3 are not in release order
        $gilberta = [['start_at' => '2026-02-07 17:00:00', 'end_at' => '2026-02-24 16:59:59']];
        $yvonne = [['start_at' => '2026-02-24 17:00:00', 'end_at' => '2026-03-11 16:59:59']];
        $this->assertSame(['up' => ['chr_0013_aglina'], 'runs' => $gilberta], array_intersect_key($pools['special_1_0_3'], array_flip(['up', 'runs'])));
        $this->assertSame(['up' => ['chr_0017_yvonne'], 'runs' => $yvonne], array_intersect_key($pools['special_1_0_2'], array_flip(['up', 'runs'])));
        $this->assertSame(['up' => ['wpn_funnel_0011'], 'runs' => [['start_at' => '2026-02-07 17:00:00', 'end_at' => '2026-03-28 16:59:59']]], array_intersect_key($pools['weponbox_1_0_3'], array_flip(['up', 'runs'])));
        $this->assertSame(['up' => ['wpn_pistol_0010'], 'runs' => [['start_at' => '2026-02-24 17:00:00', 'end_at' => '2026-04-16 16:59:59']]], array_intersect_key($pools['weponbox_1_0_2'], array_flip(['up', 'runs'])));
        $this->assertSame([['start_at' => '2026-03-29 17:00:00', 'end_at' => '2026-04-16 16:59:59']], $pools['special_1_1_2']['runs'], 'Rossi');

        // The Joint banner (exact version among the pools featuring Laevatain or Gilberta) features its four operators, AkeData lists two
        $this->assertSame(['chr_0016_laevat', 'chr_0013_aglina', 'chr_0025_ardelia', 'chr_0029_pograni'], $pools['joint_1_2_2']['up']);
        $this->assertSame([['start_at' => '2026-05-14 17:00:00', 'end_at' => '2026-06-04 16:59:59']], $pools['joint_1_2_2']['runs']);

        // Weapon 1.2.2 features two weapons, one per pool: both pools get the run, each keeps its own weapon
        $this->assertSame(['wpn_sword_0006'], $pools['weponbox_1_2_2']['up']);
        $this->assertSame(['wpn_funnel_0011'], $pools['weponbox_1_2_3']['up']);
        $this->assertSame($pools['weponbox_1_2_2']['runs'], $pools['weponbox_1_2_3']['runs']);
        $this->assertSame([['start_at' => '2026-05-14 17:00:00', 'end_at' => '2026-06-04 16:59:59']], $pools['weponbox_1_2_3']['runs']);

        // RE-Factor banners go to the rerun pool featuring their items, whatever their version
        $this->assertSame([['start_at' => '2026-09-24 17:00:00', 'end_at' => '2026-10-14 16:59:59']], $pools['rerun_chr_yvonne']['runs']);
        $this->assertSame([['start_at' => '2026-10-29 17:00:00', 'end_at' => '2026-11-19 16:59:59']], $pools['rerun_chr_tangtang']['runs']);
        $this->assertSame([['start_at' => '2026-11-25 03:00:00', 'end_at' => '2026-12-16 16:59:59']], $pools['rerun_chr_aglina']['runs']);
        $this->assertSame([['start_at' => '2026-12-16 17:00:00', 'end_at' => '2027-01-06 16:59:59']], $pools['rerun_wpn_wulfa']['runs']);
        $this->assertSame(['wpn_pistol_0011'], $pools['rerun_wpn_tangtang']['up']);

        // Start at 11:00 UTC+8, end at 11:59:59 UTC-5; the end of weapon 1.6.2 ("2026-01-07") gets its year fixed
        $this->assertSame([['start_at' => '2026-01-22 03:00:00', 'end_at' => '2026-02-07 16:59:59']], $pools['special_1_0_1']['runs']);
        $this->assertSame([['start_at' => '2026-11-05 17:00:00', 'end_at' => '2027-01-07 16:59:59']], $pools['weaponbox_1_6_2']['runs']);
        $this->assertSame(['wpn_sword_0025'], $pools['weaponbox_1_6_2']['up']);

        // The only warnings: the end year of weapon 1.6.2, fixed by the normalizer on the builder logger, and the
        // Joint pool, whose AkeData rate-ups irminsul.gg completes
        $warnings = $logger->records(LogLevel::WARNING);
        $this->assertSame(
            ['irminsul phase ends before it starts: one year added to its end', 'joint_1_2_2'],
            array_map(static fn (array $record): string => $record['context']['pool'] ?? $record['message'], $warnings),
        );
        $this->assertSame(['weapon', '1.6.2'], [$warnings[0]['context']['category'], $warnings[0]['context']['version']]);
        $this->assertSame(['chr_0016_laevat', 'chr_0013_aglina'], $warnings[1]['context']['akedata_up']);
    }

    public function testReFactorBannersOfTheSameRerunPoolAreItsRunsInStartOrder(): void
    {
        $files = new EndfieldIrminsulFixture()->files;
        // Listed first, but it comes back after the 1.7.2 one
        array_unshift($files[EndfieldIrminsulFixture::BANNER_CHARACTERS], EndfieldIrminsulFixture::banner(10030, '2.1.2', '2027-04-01 12:00:00', '2027-04-20 16:59:59', ['Gilberta'], 'RE-Factor Headhunting'));

        $pool = new AkeDataFixture()->builder(irminsul: new EndfieldIrminsulFixture($files))->build()['pools']['rerun_chr_aglina'];

        $this->assertSame([self::RUN_1_7_2, ['start_at' => '2027-04-01 17:00:00', 'end_at' => '2027-04-20 16:59:59']], $pool['runs']);
        $this->assertSame(['chr_0013_aglina'], $pool['up']);
        $this->assertSame('irminsul', $pool['up_source']);
    }

    public function testBannersWithoutAPoolAreSkippedWithAWarning(): void
    {
        $files = new EndfieldIrminsulFixture()->files;
        $files[EndfieldIrminsulFixture::BANNER_CHARACTERS] = [
            // No rerun pool features Ember
            EndfieldIrminsulFixture::banner(10040, '1.7.3', '2026-12-16 12:00:00', '2027-01-06 16:59:59', ['Ember'], 'RE-Factor Headhunting'),
            // A later banner featuring an old operator never takes a pool of another major.minor
            EndfieldIrminsulFixture::banner(10041, '2.0.1', '2027-01-07 11:00:00 UTC+8', '2027-01-27 11:59:59', ['Gilberta']),
            // No limited pool features Ember (the standard pools never do)
            EndfieldIrminsulFixture::banner(10042, '1.0.2', '2026-02-07 12:00:00', '2026-02-24 11:59:59', ['Ember']),
        ];
        $logger = self::recordingLogger();

        $pools = new AkeDataFixture()->builder($logger, new EndfieldIrminsulFixture($files))->build()['pools'];

        $this->assertSame(self::WEAPON_POOLS['weponbox_1_0_3'], $pools['weponbox_1_0_3'], 'The weapon banners still map');
        $this->assertSame([], $pools['rerun_chr_aglina']['runs']);
        $this->assertSame([], $pools['special_1_0_3']['runs']);
        $this->assertSame('akedata', $pools['special_1_0_3']['up_source']);
        $this->assertSame(
            [
                'Endfield irminsul.gg RE-Factor banner skipped: no rerun pool features its items',
                'Endfield irminsul.gg banner skipped: no pool of its major.minor features its items',
                'Endfield irminsul.gg banner skipped: no pool of its major.minor features its items',
            ],
            array_column($logger->records(LogLevel::WARNING), 'message'),
        );
    }

    public function testRegularBannerTakesTheExactVersionPoolElseTheOnlyPoolOfItsMajorMinor(): void
    {
        $operatorPools = [
            'special_1_0_1' => AkeDataFixture::poolRow('special_1_0_1', 0, 'upCharIds', ['chr_0013_aglina']),
            'special_1_0_3' => AkeDataFixture::poolRow('special_1_0_3', 0, 'upCharIds', ['chr_0013_aglina']),
            'special_1_1_1' => AkeDataFixture::poolRow('special_1_1_1', 0, 'upCharIds', ['chr_0009_azrila']),
            'special_1_1_2' => AkeDataFixture::poolRow('special_1_1_2', 0, 'upCharIds', ['chr_0009_azrila']),
            // Never the pool of a regular banner, whatever their rate-ups
            'rerun_chr_aglina' => AkeDataFixture::poolRow('rerun_chr_aglina', 4, 'upCharIds', ['chr_0009_azrila'], 1),
            'standard' => AkeDataFixture::poolRow('standard', 2, 'upCharIds', ['chr_0009_azrila']),
        ];
        $files = new EndfieldIrminsulFixture()->files;
        $files[EndfieldIrminsulFixture::BANNER_CHARACTERS] = [
            // Two 1.0 pools feature Gilberta: the one named 1.0.3 wins
            EndfieldIrminsulFixture::banner(10002, '1.0.3', '2026-02-24 12:00:00', '2026-03-11 16:59:59', ['Gilberta']),
            // Two 1.1 pools feature Ember, none is named 1.1.3
            EndfieldIrminsulFixture::banner(10005, '1.1.3', '2026-04-01 12:00:00', '2026-04-16 16:59:59', ['Ember']),
        ];
        $logger = self::recordingLogger();

        $pools = new AkeDataFixture([AkeDataFixture::LATEST_PATH . '/' . AkeDataFixture::CHAR_POOL_TABLE => $operatorPools])
            ->builder($logger, new EndfieldIrminsulFixture($files))->build()['pools'];

        $this->assertSame([self::RUN_1_0_3_CHAR], $pools['special_1_0_3']['runs']);
        $this->assertSame([], $pools['special_1_0_1']['runs']);
        foreach (['special_1_1_1', 'special_1_1_2', 'rerun_chr_aglina', 'standard'] as $poolId) {
            $this->assertSame([], $pools[$poolId]['runs'], $poolId);
        }

        $warnings = $logger->records(LogLevel::WARNING);
        $this->assertSame(['Endfield irminsul.gg banner skipped: several pools of its major.minor feature its items'], array_column($warnings, 'message'));
        $this->assertSame(['special_1_1_1', 'special_1_1_2'], $warnings[0]['context']['candidates']);
    }

    public function testMultiWeaponBannerWithoutAnExactVersionPoolOwnsThePoolsOfItsWeaponsWithoutAWarning(): void
    {
        // No "weponbox_1_2_4": the two 1.2 pools featuring its weapons, one each
        $files = [EndfieldIrminsulFixture::BANNER_WEAPONS => [
            EndfieldIrminsulFixture::banner(20030, '1.2.4', '2026-05-14 12:00:00', '2026-06-04 16:59:59', ['Forgeborn Scathe', 'Delivery Guaranteed']),
        ]];
        $logger = self::recordingLogger();

        $pools = new AkeDataFixture(AkeDataFixture::realFiles())->builder($logger, EndfieldIrminsulFixture::real($files))->build()['pools'];

        $run = [['start_at' => '2026-05-14 17:00:00', 'end_at' => '2026-06-04 16:59:59']];
        $this->assertSame(['up' => ['wpn_sword_0006'], 'up_source' => 'irminsul', 'runs' => $run], array_intersect_key($pools['weponbox_1_2_2'], array_flip(['up', 'up_source', 'runs'])));
        $this->assertSame(['up' => ['wpn_funnel_0011'], 'up_source' => 'irminsul', 'runs' => $run], array_intersect_key($pools['weponbox_1_2_3'], array_flip(['up', 'up_source', 'runs'])));
        $this->assertSame([], $pools['weponbox_1_2_1']['runs']);

        // The only warning: the operator Joint pool of the real files
        $warnings = $logger->records(LogLevel::WARNING);
        $this->assertSame(['joint_1_2_2'], array_map(static fn (array $record): string => $record['context']['pool'] ?? $record['message'], $warnings));
    }

    public function testMultiWeaponBannerWhosePoolsAreTakenIsSkippedWithAWarning(): void
    {
        $files = [EndfieldIrminsulFixture::BANNER_WEAPONS => [
            // Listed first, mapped last: the exact version banners take their pools before it
            EndfieldIrminsulFixture::banner(20030, '1.2.4', '2026-06-05 11:00:00 UTC+8', '2026-06-26 16:59:59', ['Forgeborn Scathe', 'Delivery Guaranteed']),
            EndfieldIrminsulFixture::banner(20031, '1.2.2', '2026-05-14 12:00:00', '2026-06-04 16:59:59', ['Forgeborn Scathe']),
            EndfieldIrminsulFixture::banner(20032, '1.2.3', '2026-05-14 12:00:00', '2026-06-04 16:59:59', ['Delivery Guaranteed']),
        ]];
        $logger = self::recordingLogger();

        $pools = new AkeDataFixture(AkeDataFixture::realFiles())->builder($logger, EndfieldIrminsulFixture::real($files))->build()['pools'];

        $run = [['start_at' => '2026-05-14 17:00:00', 'end_at' => '2026-06-04 16:59:59']];
        $this->assertSame($run, $pools['weponbox_1_2_2']['runs']);
        $this->assertSame($run, $pools['weponbox_1_2_3']['runs']);

        // Warned once nothing is left for it, besides the operator Joint pool of the real files
        $warnings = $logger->records(LogLevel::WARNING);
        $this->assertSame(
            ['Endfield irminsul.gg banner skipped: several pools of its major.minor feature its items, none is left for it', 'joint_1_2_2'],
            array_map(static fn (array $record): string => $record['context']['pool'] ?? $record['message'], $warnings),
        );
        $this->assertSame(['1.2.4', ['weponbox_1_2_2', 'weponbox_1_2_3']], [$warnings[0]['context']['version'], $warnings[0]['context']['candidates']]);
    }

    public function testIrminsulRateUpsThatDisagreeWithAkeDataWinWithAWarning(): void
    {
        $files = new EndfieldIrminsulFixture()->files;
        $files[EndfieldIrminsulFixture::BANNER_CHARACTERS][0]['rateUps'] = ['Gilberta', 'Ember'];
        $logger = self::recordingLogger();

        $pool = new AkeDataFixture()->builder($logger, new EndfieldIrminsulFixture($files))->build()['pools']['special_1_0_3'];

        $this->assertSame(['chr_0013_aglina', 'chr_0009_azrila'], $pool['up']);
        $this->assertSame('irminsul', $pool['up_source']);
        $this->assertSame([self::RUN_1_0_3_CHAR], $pool['runs']);

        $warnings = $logger->records(LogLevel::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame('Endfield pool rate-ups taken from irminsul.gg, AkeData lists others', $warnings[0]['message']);
        $this->assertSame(['special_1_0_3', ['1.0.3'], ['chr_0013_aglina', 'chr_0009_azrila'], ['chr_0013_aglina']], [
            $warnings[0]['context']['pool'],
            $warnings[0]['context']['irminsul_versions'],
            $warnings[0]['context']['irminsul_up'],
            $warnings[0]['context']['akedata_up'],
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unresolvedFeaturedItemProvider(): iterable
    {
        yield 'unknown name' => [[], 'no irminsul item has this name'];
        yield 'no rarity' => [[EndfieldIrminsulFixture::CHARACTERS => [['id' => 1099, 'name' => 'Nameless']]], 'the irminsul item has no rarity'];
        yield 'no AkeData item' => [[EndfieldIrminsulFixture::CHARACTERS => [EndfieldIrminsulFixture::item(1099, 'Nameless', 'Nameless', 6)]], 'no AkeData item has this id'];
        yield 'id outside the operator range' => [[EndfieldIrminsulFixture::CHARACTERS => [EndfieldIrminsulFixture::item(2013, 'Nameless', 'Nameless', 6)]], 'no AkeData item has this id'];
    }

    /**
     * @param array<string, mixed> $files
     */
    #[DataProvider('unresolvedFeaturedItemProvider')]
    public function testUnresolvedFeaturedItemKeepsTheAkeDataRateUps(array $files, string $reason): void
    {
        $irminsul = new EndfieldIrminsulFixture();
        if (isset($files[EndfieldIrminsulFixture::CHARACTERS])) {
            $files[EndfieldIrminsulFixture::CHARACTERS] = [...$irminsul->files[EndfieldIrminsulFixture::CHARACTERS], ...$files[EndfieldIrminsulFixture::CHARACTERS]];
        }
        $files[EndfieldIrminsulFixture::BANNER_CHARACTERS] = $irminsul->files[EndfieldIrminsulFixture::BANNER_CHARACTERS];
        $files[EndfieldIrminsulFixture::BANNER_CHARACTERS][0]['rateUps'] = ['Gilberta', 'Nameless'];
        $logger = self::recordingLogger();

        $pool = new AkeDataFixture()->builder($logger, new EndfieldIrminsulFixture($files))->build()['pools']['special_1_0_3'];

        // The run is known, the featured list is not complete: AkeData's stays
        $this->assertSame([self::RUN_1_0_3_CHAR], $pool['runs']);
        $this->assertSame(['chr_0013_aglina'], $pool['up']);
        $this->assertSame('akedata', $pool['up_source']);

        $warnings = $logger->records(LogLevel::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame(['Nameless', $reason], [$warnings[0]['context']['name'], $warnings[0]['context']['reason']]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function irminsulUnavailableProvider(): iterable
    {
        yield 'down' => [EndfieldIrminsulFixture::down()->files, []];
        yield 'one file not found' => [[EndfieldIrminsulFixture::BANNER_WEAPONS => null], []];
        yield 'invalid JSON' => [[EndfieldIrminsulFixture::WEAPONS => '{"broken'], []];
        // The normalizer logs the dropped entry on the builder logger
        yield 'no usable banner' => [[EndfieldIrminsulFixture::BANNER_CHARACTERS => [['id' => 'x', 'version' => '1.0.3']]], ['irminsul banner entry skipped: malformed']];
    }

    /**
     * @param array<string, mixed> $files
     * @param list<string>         $normalizerWarnings
     */
    #[DataProvider('irminsulUnavailableProvider')]
    public function testIrminsulUnavailableKeepsThePreviousRunsAndRateUps(array $files, array $normalizerWarnings): void
    {
        $previousRun = ['start_at' => '2026-02-24 17:00:00', 'end_at' => '2026-03-11 16:59:59'];
        $previous = new AkeCatalog(new ArrayAdapter());
        $previous->save([
            'version' => AkeDataFixture::OLDER,
            'built_at' => '2026-11-16T06:00:00+01:00',
            'items' => ['chr_0013_aglina' => ['id' => 'chr_0013_aglina', 'name' => 'Gilberta', 'rarity' => 6, 'type' => 'char']],
            'pools' => [
                // Runs and irminsul rate-ups: both kept
                'special_1_0_3' => ['id' => 'special_1_0_3', 'category' => 'char', 'type' => 0, 'up' => ['chr_0009_azrila'], 'up_source' => 'irminsul', 'runs' => [$previousRun]],
                // Runs known, rate-ups from AkeData: the fresh AkeData list wins
                'joint_1_2_2' => ['id' => 'joint_1_2_2', 'category' => 'char', 'type' => 3, 'up' => ['chr_0099_old'], 'up_source' => 'akedata', 'runs' => [self::RUN_1_7_2]],
                // Same id, other category: nothing is kept
                'rerun_wpn_aglina' => ['id' => 'rerun_wpn_aglina', 'category' => 'char', 'type' => 4, 'up' => ['chr_0013_aglina'], 'up_source' => 'irminsul', 'runs' => [self::RUN_1_7_2]],
                // Stored before the irminsul fields
                'rerun_chr_aglina' => ['id' => 'rerun_chr_aglina', 'category' => 'char', 'type' => 4, 'up' => ['chr_0013_aglina']],
            ],
        ]);
        $logger = self::recordingLogger();

        $catalog = new AkeDataFixture()->builder($logger, new EndfieldIrminsulFixture($files), $previous)->build();
        $pools = $catalog['pools'];

        $this->assertCount(6, $catalog['items'], 'The build goes on');
        $this->assertSame(['id' => 'special_1_0_3', 'category' => 'char', 'type' => 0, 'up' => ['chr_0009_azrila'], 'up_source' => 'irminsul', 'runs' => [$previousRun]], $pools['special_1_0_3']);
        $this->assertSame(['up' => ['chr_0016_laevat', 'chr_0013_aglina'], 'up_source' => 'akedata', 'runs' => [self::RUN_1_7_2]], array_intersect_key($pools['joint_1_2_2'], array_flip(['up', 'up_source', 'runs'])));
        $this->assertSame(['up' => ['wpn_funnel_0011'], 'up_source' => 'akedata', 'runs' => []], array_intersect_key($pools['rerun_wpn_aglina'], array_flip(['up', 'up_source', 'runs'])));
        $this->assertSame(['up' => ['chr_0013_aglina'], 'up_source' => 'akedata', 'runs' => []], array_intersect_key($pools['rerun_chr_aglina'], array_flip(['up', 'up_source', 'runs'])));
        $this->assertSame(self::CHAR_POOLS['standard'], $pools['standard']);

        $warnings = $logger->records(LogLevel::WARNING);
        $this->assertSame([...$normalizerWarnings, 'Endfield catalog built without the irminsul.gg banners: the previous runs and rate-ups are kept'], array_column($warnings, 'message'));
        $this->assertSame(AkeDataFixture::LATEST, $warnings[array_key_last($warnings)]['context']['version']);
    }

    private function createBuilder(AkeDataFixture $fixture): AkeCatalogBuilder
    {
        return $fixture->builder();
    }

    /**
     * Logger keeping its records, for the tests that check several warnings.
     */
    private static function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }

            /**
             * @return list<array{level: string, message: string, context: array<string, mixed>}>
             */
            public function records(string $level): array
            {
                return array_values(array_filter($this->records, static fn (array $record): bool => $record['level'] === $level));
            }
        };
    }
}
