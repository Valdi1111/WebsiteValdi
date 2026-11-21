<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\Catalog\NteCatalogBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
    ];

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

        // build() does not persist: the command saves the result
        $this->assertSame(self::PREVIOUS_CATALOG['sha'], $nteCatalog->getSha());
    }

    private function createBuilder(NteAssetsFixture $fixture, ?NteCatalog $catalog = null): NteCatalogBuilder
    {
        return new NteCatalogBuilder($fixture->client(), $catalog ?? $this->createCatalog(), new NullLogger());
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
