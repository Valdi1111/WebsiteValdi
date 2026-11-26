<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class NteCatalogTest extends TestCase
{
    private const string SHA = '0123456789abcdef0123456789abcdef01234567';

    private const array CATALOG = [
        'sha' => self::SHA,
        'version' => '1.5.4',
        'built_at' => '2026-11-23T06:00:00+01:00',
        'items' => [
            '1042' => ['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'UI_Icon/AvatarImage/200/player_heiyu.png'],
            'fork_vine' => ['id' => 'fork_vine', 'name' => 'Be Happy', 'rank' => 'B', 'type' => 'arc', 'icon_path' => 'UI_Icon/Fork/fork_HugVine_256.png'],
            'fashion_glide_1075' => ['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI/Appearance/Glider/Fashion_Glide_1075.png'],
            'fashion_1042_1' => ['id' => 'Fashion_1042_1', 'name' => 'Wandering Stars', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI_Icon/AvatarImage/256/player_heiyu1_256.png'],
            'green_item' => ['id' => 'Green_item', 'name' => 'Green Thing', 'rank' => null, 'type' => 'item', 'icon_path' => null],
            'spaced' => ['id' => 'Spaced', 'name' => 'Spaced', 'rank' => 'B', 'type' => 'item', 'icon_path' => 'UI_Icon/Item/with space.png'],
        ],
        'avatars' => ['1042' => 'UI_Icon/AvatarImage/256/player_heiyu_256.png'],
        'pools' => ['lottery_heiyu' => 'Foretold Finale', 'forklottery_heiyu' => 'Foretold Special'],
        'banners' => [
            'forklottery_heiyu' => ['id' => 'ForkLottery_HeiYu', 'category' => 'arc', 'name' => 'Foretold Special', 'up' => ['fork_twinbirds'], 'others' => ['fork_Butterfly', 'fork_BlackBook'], 'up_guarantee' => 80],
            // Unusable values are dropped
            'forklottery_mingyin' => ['id' => 'ForkLottery_MingYin', 'category' => 'arc', 'name' => '', 'up' => ['fork_prism', '', 42, null], 'others' => ['fork_Butterfly', 'fork_Shared'], 'up_guarantee' => '80'],
            // Ended pool: "fork_Shared" was featured here and is standard in ForkLottery_MingYin
            'forklottery_old' => ['id' => 'ForkLottery_Old', 'category' => 'arc', 'name' => 'Old Special', 'up' => ['fork_Shared'], 'others' => [], 'up_guarantee' => 80],
            'forklottery_noup' => ['id' => 'ForkLottery_NoUp', 'category' => 'arc', 'name' => null, 'up' => [], 'others' => ['fork_BlackBook'], 'up_guarantee' => null],
            'forklottery_noid' => ['category' => 'arc', 'up' => 'fork_twinbirds', 'others' => null],
            // Boards never decide whether an Arc is featured
            'lottery_heiyu' => ['id' => 'Lottery_HeiYu', 'category' => 'board', 'name' => 'Foretold Finale', 'up' => ['1042', 'fork_BoardOnly'], 'others' => [], 'up_guarantee' => null],
            'lottery_event' => ['id' => 'Lottery_Event', 'category' => 'event', 'name' => 'Event', 'up' => ['fork_twinbirds'], 'others' => [], 'up_guarantee' => null],
            'lottery_scalar' => 'Foretold Finale',
        ],
    ];

    public function testLookupsAreCaseInsensitive(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        $this->assertTrue($catalog->isLoaded());
        $this->assertSame(self::SHA, $catalog->getSha());
        $this->assertSame('1.5.4', $catalog->getVersion());

        $this->assertSame(
            ['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'rarity' => 4, 'type' => 'cosmetic', 'icon_path' => 'UI/Appearance/Glider/Fashion_Glide_1075.png'],
            $catalog->find('Fashion_Glide_1075'),
        );
        $this->assertSame(5, $catalog->find('1042')['rarity']);
        $this->assertSame(3, $catalog->find('FORK_VINE')['rarity']);
        $this->assertNull($catalog->find('green_item')['rarity']);
        $this->assertNull($catalog->find('unknown_id'));
    }

    public function testIconUrlsPointToTheCatalogCommit(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);
        $base = 'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/';

        $this->assertSame($base . 'UI_Icon/Fork/fork_HugVine_256.png', $catalog->getIconUrl('fork_vine'));
        $this->assertSame($base . 'UI/Appearance/Glider/Fashion_Glide_1075.png', $catalog->getIconUrl('fashion_glide_1075'));
        $this->assertSame($base . 'UI_Icon/Item/with%20space.png', $catalog->getIconUrl('spaced'));
        $this->assertNull($catalog->getIconUrl('green_item'));
        $this->assertNull($catalog->getIconUrl('unknown_id'));
    }

    public function testAvatarAndPoolLookups(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);
        $base = 'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/';

        $this->assertSame($base . 'UI_Icon/AvatarImage/256/player_heiyu_256.png', $catalog->findAvatarIconUrl('1042'));
        // Not in the avatar table: falls back to the item with the same id
        $this->assertSame($base . 'UI_Icon/AvatarImage/256/player_heiyu1_256.png', $catalog->findAvatarIconUrl('Fashion_1042_1'));
        $this->assertNull($catalog->findAvatarIconUrl('Fashion_9999_1'));
        $this->assertNull($catalog->findAvatarIconUrl(null));
        $this->assertNull($catalog->findAvatarIconUrl(''));

        $this->assertSame('Foretold Finale', $catalog->findPoolName('Lottery_HeiYu'));
        $this->assertSame('Foretold Special', $catalog->findPoolName('ForkLottery_HeiYu'));
        $this->assertNull($catalog->findPoolName('Lottery_Unknown'));
        $this->assertNull($catalog->findPoolName(null));
    }

    /** Fields of a banner of a catalog built before the runs */
    private const array NO_RUN = ['up_source' => 'nte_assets', 'runs' => []];

    public function testFindPool(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        $this->assertSame(
            ['id' => 'ForkLottery_HeiYu', 'category' => 'arc', 'name' => 'Foretold Special', 'up' => ['fork_twinbirds'], 'others' => ['fork_Butterfly', 'fork_BlackBook'], 'up_guarantee' => 80] + self::NO_RUN,
            $catalog->findPool(' forklottery_HEIYU '),
        );
        $this->assertSame(
            ['id' => 'Lottery_HeiYu', 'category' => 'board', 'name' => 'Foretold Finale', 'up' => ['1042', 'fork_BoardOnly'], 'others' => [], 'up_guarantee' => null] + self::NO_RUN,
            $catalog->findPool('Lottery_HeiYu'),
        );

        // List fields keep non-empty strings only, blank name and non-int guarantee are null
        $this->assertSame(
            ['id' => 'ForkLottery_MingYin', 'category' => 'arc', 'name' => null, 'up' => ['fork_prism'], 'others' => ['fork_Butterfly', 'fork_Shared'], 'up_guarantee' => null] + self::NO_RUN,
            $catalog->findPool('ForkLottery_MingYin'),
        );
        // Missing id: the requested one, trimmed; list fields that are not lists are empty
        $this->assertSame(
            ['id' => 'ForkLottery_NoId', 'category' => 'arc', 'name' => null, 'up' => [], 'others' => [], 'up_guarantee' => null] + self::NO_RUN,
            $catalog->findPool(' ForkLottery_NoId '),
        );

        $this->assertNull($catalog->findPool('Lottery_Event'));
        $this->assertNull($catalog->findPool('Lottery_Scalar'));
        $this->assertNull($catalog->findPool('Lottery_Unknown'));
        $this->assertNull($catalog->findPool(' '));
        $this->assertNull($catalog->findPool(null));
    }

    public function testFindPoolReturnsTheRuns(): void
    {
        $stored = self::CATALOG;
        $stored['banners']['forklottery_nanali'] = [
            'id' => 'ForkLottery_Nanali',
            'category' => 'arc',
            'name' => 'Tiger Special',
            'up' => ['fork_TigerTally'],
            'others' => [],
            'up_guarantee' => 80,
            'up_source' => 'nte_assets',
            // Unsorted, duplicated and unusable runs
            'runs' => [
                ['start_at' => '2026-08-19 03:00:00', 'end_at' => '2026-09-08 21:59:59'],
                ['start_at' => '2026-04-29 03:00:00', 'end_at' => '2026-05-12 21:59:59'],
                ['start_at' => '2026-08-19 03:00:00', 'end_at' => '2026-09-08 21:59:59'],
                ['start_at' => '2026-05-12 21:59:59', 'end_at' => '2026-04-29 03:00:00'],
                ['start_at' => '2026-02-30 03:00:00', 'end_at' => '2026-03-08 21:59:59'],
                ['start_at' => '2026-06-01T03:00:00Z', 'end_at' => '2026-06-08 21:59:59'],
                ['start_at' => '2026-06-01 03:00:00'],
                'broken',
            ],
        ];
        $stored['banners']['lottery_nanali'] = ['id' => 'Lottery_Nanali', 'category' => 'board', 'up' => ['1010'], 'up_source' => '', 'runs' => 'broken'];
        $catalog = $this->createCatalog($stored);

        $pool = $catalog->findPool('forklottery_NANALI');
        $this->assertSame([
            ['start_at' => '2026-04-29 03:00:00', 'end_at' => '2026-05-12 21:59:59'],
            ['start_at' => '2026-08-19 03:00:00', 'end_at' => '2026-09-08 21:59:59'],
        ], $pool['runs']);
        $this->assertSame('nte_assets', $pool['up_source']);
        $this->assertSame(['id', 'category', 'name', 'up', 'others', 'up_guarantee', 'up_source', 'runs'], array_keys($pool));

        // Unusable values read as without them
        $board = $catalog->findPool('Lottery_Nanali');
        $this->assertSame(self::NO_RUN, ['up_source' => $board['up_source'], 'runs' => $board['runs']]);
    }

    public function testIsArcUpFollowsTheKnownArcPool(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        $this->assertTrue($catalog->isArcUp('FORK_TWINBIRDS', 'forklottery_heiyu'));
        $this->assertFalse($catalog->isArcUp('fork_butterfly', 'ForkLottery_HeiYu'));
        // Featured in another pool: lost the rate-up of this one
        $this->assertFalse($catalog->isArcUp('fork_prism', 'ForkLottery_HeiYu'));
        $this->assertTrue($catalog->isArcUp('fork_shared', 'ForkLottery_Old'));
        $this->assertFalse($catalog->isArcUp('fork_shared', 'ForkLottery_MingYin'));
        $this->assertNull($catalog->isArcUp(' ', 'ForkLottery_HeiYu'));
    }

    public function testIsArcUpWithoutAKnownArcPoolChecksEveryArcBanner(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        foreach ([null, 'ForkLottery_Unknown', 'Lottery_HeiYu', 'ForkLottery_NoUp', 'Lottery_Event'] as $poolId) {
            // Only ever featured: limited Arc
            $this->assertTrue($catalog->isArcUp('fork_twinbirds', $poolId));
            $this->assertTrue($catalog->isArcUp('Fork_Prism', $poolId));
            // Only ever among the other S Arcs: standard Arc
            $this->assertFalse($catalog->isArcUp('FORK_BUTTERFLY', $poolId));
            $this->assertFalse($catalog->isArcUp('fork_BlackBook', $poolId));
            // Featured in one pool, standard in another, unknown or only on a board
            $this->assertNull($catalog->isArcUp('fork_Shared', $poolId));
            $this->assertNull($catalog->isArcUp('fork_unknown', $poolId));
            $this->assertNull($catalog->isArcUp('fork_BoardOnly', $poolId));
        }
    }

    public function testCatalogBuiltBeforeBannersHasNoBanner(): void
    {
        $stored = self::CATALOG;
        unset($stored['banners']);
        $catalog = $this->createCatalog($stored);

        $this->assertSame([], $catalog->all()['banners']);
        $this->assertNull($catalog->findPool('ForkLottery_HeiYu'));
        $this->assertNull($catalog->isArcUp('fork_twinbirds', 'ForkLottery_HeiYu'));
        $this->assertNull($catalog->isArcUp('fork_twinbirds'));
        // Pool names are unaffected
        $this->assertSame('Foretold Special', $catalog->findPoolName('ForkLottery_HeiYu'));
    }

    public function testEmptyPoolMeansNotLoaded(): void
    {
        $catalog = new NteCatalog(new ArrayAdapter());

        $this->assertFalse($catalog->isLoaded());
        $this->assertNull($catalog->getSha());
        $this->assertNull($catalog->getVersion());
        $this->assertNull($catalog->find('1042'));
        $this->assertNull($catalog->getIconUrl('1042'));
        $this->assertNull($catalog->findAvatarIconUrl('1042'));
        $this->assertNull($catalog->findPoolName('Lottery_HeiYu'));
        $this->assertNull($catalog->findPool('Lottery_HeiYu'));
        $this->assertNull($catalog->isArcUp('fork_twinbirds'));
        $this->assertSame([], $catalog->all()['items']);
        $this->assertSame([], $catalog->all()['banners']);
    }

    public function testSaveRefreshesTheMemoAndResetRereadsThePool(): void
    {
        $pool = new ArrayAdapter();
        $reader = new NteCatalog($pool);
        $this->assertFalse($reader->isLoaded());

        // Another process (the weekly command) saves a catalog into the shared pool
        $writer = new NteCatalog($pool);
        $writer->save(self::CATALOG);
        $this->assertSame('Blackbird', $writer->find('1042')['name']);

        // The reader keeps its memo until the kernel resets it between worker messages
        $this->assertFalse($reader->isLoaded());
        $reader->reset();
        $this->assertTrue($reader->isLoaded());
        $this->assertSame('Blackbird', $reader->find('1042')['name']);
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function createCatalog(array $stored): NteCatalog
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        $catalog->save($stored);
        $catalog->reset();

        return $catalog;
    }
}
