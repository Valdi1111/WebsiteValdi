<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\HoyoversePullStatsCalculator;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HoyoversePullStatsCalculatorTest extends TestCase
{
    private int $nextId = 1;
    private int $minute = 0;

    /**
     * A row as returned by HoyoversePullRepository::findBannerRows().
     *
     * @return array<string, mixed>
     */
    private function row(int $rarity, string $name = 'Filler', string $gachaType = '301', string $month = '2026-06', ?string $gachaName = 'Character Event Wish'): array
    {
        ++$this->minute;
        $id = $this->nextId++;

        return [
            'id' => $id,
            'pullId' => sprintf('17000000000000%05d', $id),
            'gachaType' => $gachaType,
            'gachaName' => $gachaName,
            'itemType' => $rarity >= 5 ? 'Character' : 'Weapon',
            'itemId' => strtolower($name),
            'name' => $name,
            'rarity' => $rarity,
            'pulledAt' => new \DateTimeImmutable(sprintf('%s-01 %02d:%02d:00', $month, intdiv($this->minute, 60) % 24, $this->minute % 60)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $count, int $rarity = 3, string $gachaType = '301', string $month = '2026-06'): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $rows[] = $this->row($rarity, 'Filler', $gachaType, $month);
        }

        return $rows;
    }

    private static function calculator(): HoyoversePullStatsCalculator
    {
        return new HoyoversePullStatsCalculator(new PullGapPlanner());
    }

    private static function genshin(): GenshinImpactService
    {
        return new GenshinImpactService(new NullLogger());
    }

    private static function genshinRarity(): RarityConfig
    {
        return self::genshin()->getRarityConfig();
    }

    /**
     * @return array<string, mixed>
     */
    private static function genshinGroup(string $key): array
    {
        return self::genshin()->getGachaConfig()->getBannerGroups()[$key];
    }

    public function testCountsSPityAndAnnotatesEveryRow(): void
    {
        $rows = [
            ...$this->rows(73),
            $this->row(5, 'Furina'), // pull 74
            ...$this->rows(9),
            $this->row(5, 'Neuvillette'), // pull 84 (pity 10)
            ...$this->rows(2),
        ];

        $calculator = self::calculator();
        $result = $calculator->compute($rows, self::genshinGroup('301'), self::genshinRarity());
        $summary = $result['summary'];

        $this->assertSame(86, $summary['total_pulls']);
        $this->assertSame(86 * HoyoversePullStatsCalculator::CURRENCY_PER_PULL, $summary['currency_spent']);
        $this->assertSame(13760, $summary['currency_spent'], '160 per pull');
        $this->assertSame(2, $summary['s_count']);
        $this->assertSame(0, $summary['a_count']);
        $this->assertSame(84, $summary['b_count']);
        $this->assertSame(2, $summary['s_pity'], 'Two pulls after the last S');
        $this->assertSame(42.0, $summary['avg_s_pity'], '(74 + 10) / 2');
        $this->assertSame(0.0, $summary['avg_a_pity']);
        $this->assertSame(['301', '400'], $summary['gacha_types']);
        $this->assertSame(90, $summary['s_max_pity']);
        $this->assertSame(10, $summary['a_max_pity']);
        $this->assertSame(
            ['key', 'name', 'gacha_types', 'total_pulls', 'currency_spent', 's_count', 'a_count', 'b_count', 's_pity', 's_max_pity', 'a_pity', 'a_max_pity', 'avg_s_pity', 'avg_a_pity', 'recent_s_pulls', 'unknown_count', 'open_window', 'oldest_s'],
            array_keys($summary),
        );

        // Recent S pulls: newest first, with their own key set
        $this->assertSame(['Neuvillette', 'Furina'], array_column($summary['recent_s_pulls'], 'name'));
        $this->assertSame([
            'id' => 84,
            'pull_number' => 84,
            'item_id' => 'neuvillette',
            'name' => 'Neuvillette',
            'item_type' => 'Character',
            'rarity' => 5,
            'pity' => 10,
            'pulled_at' => $rows[83]['pulledAt']->format('Y-m-d H:i:s'),
            'origin' => 'recorded',
            'declared_pity' => null,
            'recorded_pity' => 10,
            'insert_after' => $rows[73]['pulledAt']->format('Y-m-d H:i:s'),
            'insert_before' => $rows[74]['pulledAt']->format('Y-m-d H:i:s'),
            // Neighbours one minute apart: the midpoint
            'suggested_at' => $rows[73]['pulledAt']->modify('+30 seconds')->format('Y-m-d H:i:s'),
            'has_room' => true,
        ], $summary['recent_s_pulls'][0]);
        $this->assertSame(74, $summary['recent_s_pulls'][1]['pity']);

        $items = $result['items'];
        $this->assertCount(86, $items);
        $this->assertSame(
            ['id', 'pull_number', 'pull_id', 'gacha_type', 'gacha_name', 'item_type', 'item_id', 'name', 'rarity', 'pity', 'pulled_at', 'origin', 'declared_pity'],
            array_keys($items[0]),
            'New keys come after the existing ones',
        );
        $this->assertSame(
            ['id', 'pull_number', 'pull_id', 'gacha_type', 'gacha_name', 'item_type', 'item_id', 'name', 'rarity', 'pity', 'pulled_at', 'origin', 'declared_pity', 'recorded_pity', 'insert_after', 'insert_before', 'suggested_at', 'has_room'],
            array_keys($items[73]),
            'S rows also carry their window',
        );
        $this->assertSame(1, $items[0]['pull_number']);
        $this->assertSame(1, $items[0]['pity'], 'B rows always carry pity 1');
        $this->assertSame(74, $items[73]['pity']);
        $this->assertSame(74, $items[73]['pull_number']);
        $this->assertSame($rows[73]['pullId'], $items[73]['pull_id']);
    }

    public function testRarityAboveSCountsAsSAndOnlyExactARarityCountsAsA(): void
    {
        $rarity = self::genshinRarity();
        $calculator = self::calculator();

        $this->assertTrue($calculator->isS(['rarity' => 5], $rarity));
        $this->assertTrue($calculator->isS(['rarity' => 6], $rarity));
        $this->assertFalse($calculator->isS(['rarity' => 4], $rarity));
        $this->assertTrue($calculator->countsAsPull($this->row(3)));

        $result = $calculator->compute([$this->row(6, 'Odd'), $this->row(2, 'Odd B'), $this->row(4, 'Bennett')], self::genshinGroup('301'), $rarity);

        $this->assertSame(1, $result['summary']['s_count']);
        $this->assertSame(1, $result['summary']['a_count']);
        $this->assertSame(1, $result['summary']['b_count'], 'A rarity below A is a B');
        $this->assertSame(1, $result['items'][1]['pity']);
    }

    public function testFourStarPityWithoutSResettingIt(): void
    {
        $rows = [
            ...$this->rows(9),
            $this->row(4, 'Bennett'), // A pity 10
            ...$this->rows(2),
            $this->row(5, 'Furina'), // A pity keeps running (Genshin)
            ...$this->rows(3),
            $this->row(4, 'Xingqiu'), // A pity 7
            $this->row(3),
        ];

        $result = (self::calculator())->compute($rows, self::genshinGroup('301'), self::genshinRarity());

        $this->assertFalse(self::genshinRarity()->isSResetsAPity());
        $this->assertSame(10, $result['items'][9]['pity']);
        $this->assertSame(7, $result['items'][16]['pity'], 'The S did not reset the A pity');
        $this->assertSame(2, $result['summary']['a_count']);
        $this->assertSame(8.5, $result['summary']['avg_a_pity'], '(10 + 7) / 2');
        $this->assertSame(1, $result['summary']['a_pity']);
        $this->assertSame(13, $result['items'][12]['pity'], 'S pity of the S');
    }

    public function testSResetsAPityWhenTheGameSaysSo(): void
    {
        $zzz = new ZenlessZoneZeroService(new NullLogger());
        $rarity = $zzz->getRarityConfig();
        $this->assertTrue($rarity->isSResetsAPity());

        $rows = [
            ...$this->rows(2, 2, '2'),
            $this->row(3, 'Anby', '2'), // A pity 3
            ...$this->rows(2, 2, '2'),
            $this->row(4, 'Ellen', '2'), // S rank: S pity 6, resets A pity
            ...$this->rows(3, 2, '2'),
            $this->row(3, 'Nicole', '2'), // A pity 4 (would be 7 without the reset)
            $this->row(2, 'B', '2'),
        ];

        $result = (self::calculator())->compute($rows, $zzz->getGachaConfig()->getBannerGroups()['2'], $rarity);
        $summary = $result['summary'];

        $this->assertSame(3, $result['items'][2]['pity']);
        $this->assertSame(6, $result['items'][5]['pity']);
        $this->assertSame(4, $result['items'][9]['pity']);
        $this->assertSame(1, $summary['s_count']);
        $this->assertSame(2, $summary['a_count']);
        $this->assertSame(8, $summary['b_count']);
        $this->assertSame(5, $summary['s_pity']);
        $this->assertSame(1, $summary['a_pity']);
        $this->assertSame(3.5, $summary['avg_a_pity']);
    }

    public function testEmptyBannerKeepsZeroedSummary(): void
    {
        $result = (self::calculator())->compute([], self::genshinGroup('100'), self::genshinRarity());

        $this->assertSame(0, $result['summary']['total_pulls']);
        $this->assertSame(0, $result['summary']['currency_spent']);
        $this->assertSame(0.0, $result['summary']['avg_s_pity']);
        $this->assertSame(20, $result['summary']['s_max_pity']);
        $this->assertSame([], $result['summary']['recent_s_pulls']);
        $this->assertNull($result['summary']['oldest_s']);
        $this->assertSame([], $result['items']);
        $this->assertSame([], $result['monthly']);
    }

    public function testGachaNameFallsBackToTheBannerName(): void
    {
        $row = $this->row(3, gachaName: '');

        $result = (self::calculator())->compute([$row], self::genshinGroup('301'), self::genshinRarity());

        $this->assertSame('Character Event Wish', $result['items'][0]['gacha_name']);
    }

    public function testMonthlyCountsPullsSAndA(): void
    {
        $rows = [
            ...$this->rows(3, 3, '301', '2026-05'),
            $this->row(4, 'Bennett', '301', '2026-05'),
            $this->row(5, 'Furina', '301', '2026-06'),
            $this->row(4, 'Xingqiu', '301', '2026-06'),
            ...$this->rows(2, 3, '301', '2026-07'),
        ];

        $result = (self::calculator())->compute($rows, self::genshinGroup('301'), self::genshinRarity());

        $this->assertSame([
            '2026-05' => ['month' => '2026-05', 'total' => 4, 's_count' => 0, 'a_count' => 1],
            '2026-06' => ['month' => '2026-06', 'total' => 2, 's_count' => 1, 'a_count' => 1],
            '2026-07' => ['month' => '2026-07', 'total' => 2, 's_count' => 0, 'a_count' => 0],
        ], $result['monthly']);
    }

    public function testGroupsRowsIntoConfiguredThenDynamicGroups(): void
    {
        $rows = [
            $this->row(3, gachaType: '999', gachaName: 'Mystery Wish'),
            $this->row(3, gachaType: '400', gachaName: 'Character Event Wish-2'),
            $this->row(3, gachaType: '200', gachaName: 'Standard Wish'),
            $this->row(3, gachaType: '777', gachaName: ''),
            $this->row(3, gachaType: '999', gachaName: 'Renamed later'),
            $this->row(3, gachaType: '301'),
        ];

        $grouped = (self::calculator())->groupRowsByBanner($rows, self::genshin()->getGachaConfig()->getBannerGroups());

        $this->assertTrue(array_is_list($grouped));
        $this->assertSame(['301', '302', '200', '100', '500', '999', '777'], array_map(static fn (array $g): string => $g['group']['key'], $grouped));
        $this->assertSame([2, 6], array_column($grouped[0]['rows'], 'id'), '301 and 400 share the configured group, in row order');
        $this->assertSame([], $grouped[1]['rows'], 'Configured groups are kept even when empty');
        $this->assertSame([3], array_column($grouped[2]['rows'], 'id'));
        $this->assertSame(self::genshinGroup('301'), $grouped[0]['group']);

        $this->assertSame([
            'key' => '999',
            'name' => 'Mystery Wish',
            'gacha_types' => ['999'],
            's_max_pity' => 90,
            'a_max_pity' => 10,
        ], $grouped[5]['group'], 'Named after its first row, with default pity limits');
        $this->assertSame([1, 5], array_column($grouped[5]['rows'], 'id'));
        $this->assertSame('Banner 777', $grouped[6]['group']['name']);
    }

    public function testUnconfiguredTypeMatchingAConfiguredKeyJoinsThatGroup(): void
    {
        $groups = ['301' => ['key' => '301', 'name' => 'Character Event Wish', 'gacha_types' => ['400'], 's_max_pity' => 90, 'a_max_pity' => 10]];
        $rows = [$this->row(3, gachaType: '301'), $this->row(3, gachaType: '400')];

        $grouped = (self::calculator())->groupRowsByBanner($rows, $groups);

        $this->assertCount(1, $grouped);
        $this->assertSame([1, 2], array_column($grouped[0]['rows'], 'id'));
    }

    public function testGroupsWithoutConfigurationAreAllDynamic(): void
    {
        $rows = [$this->row(3, gachaType: '302', gachaName: 'Weapon Event Wish'), $this->row(3, gachaType: '200', gachaName: null)];

        $grouped = (self::calculator())->groupRowsByBanner($rows, []);

        $this->assertSame(['302', '200'], array_map(static fn (array $g): string => $g['group']['key'], $grouped));
        $this->assertSame('Banner 200', $grouped[1]['group']['name']);
    }

    public function testFilterAndPaginateNewestFirstByRarityAndName(): void
    {
        $rows = [
            $this->row(3, 'Slingshot'),
            $this->row(4, 'Bennett'),
            $this->row(3, 'Cool Steel'),
            $this->row(5, 'Furina'),
            $this->row(4, 'Xingqiu'),
            $this->row(3, 'Ferrous Shadow'),
            $this->row(4, 'Fischl'),
        ];
        $calculator = self::calculator();
        $items = $calculator->compute($rows, self::genshinGroup('301'), self::genshinRarity())['items'];

        $all = $calculator->filterAndPaginate($items, [], null, 1, 3);
        $this->assertSame(7, $all['total']);
        $this->assertSame(['Fischl', 'Ferrous Shadow', 'Xingqiu'], array_column($all['items'], 'name'), 'Newest first');

        $page2 = $calculator->filterAndPaginate($items, [], null, 2, 3);
        $this->assertSame(['Furina', 'Cool Steel', 'Bennett'], array_column($page2['items'], 'name'));
        $this->assertSame(7, $page2['total']);

        $pastEnd = $calculator->filterAndPaginate($items, [], null, 4, 3);
        $this->assertSame([], $pastEnd['items']);
        $this->assertSame(7, $pastEnd['total']);

        $sa = $calculator->filterAndPaginate($items, [5, 4], null, 1, 10);
        $this->assertSame(['Fischl', 'Xingqiu', 'Furina', 'Bennett'], array_column($sa['items'], 'name'));

        $search = $calculator->filterAndPaginate($items, [], '  fUr ', 1, 10);
        $this->assertSame(['Furina'], array_column($search['items'], 'name'), 'Trimmed, case-insensitive');

        $both = $calculator->filterAndPaginate($items, [3], 'f', 1, 10);
        $this->assertSame(['Ferrous Shadow'], array_column($both['items'], 'name'));
        $this->assertSame(1, $both['total']);

        $none = $calculator->filterAndPaginate($items, [], 'nobody', 1, 10);
        $this->assertSame(['items' => [], 'total' => 0], $none);
    }

    // ---- Missing pulls (filler rule, windows, realign) ----

    /**
     * A row with an explicit date, tagged with Europe/Rome as Doctrine hydrates the stored wall-clock value.
     *
     * @return array<string, mixed>
     */
    private static function pull(int $id, string $at, int $rarity, string $name = 'Filler', PullOrigin $origin = PullOrigin::Recorded, ?int $declaredPity = null, string $gachaType = '301'): array
    {
        return [
            'id' => $id,
            'pullId' => match ($origin) {
                PullOrigin::Manual => sprintf('manual_%08d', $id),
                PullOrigin::Filler => sprintf('filler_%08d', $id),
                PullOrigin::Recorded => sprintf('17000000000000%05d', $id),
            },
            'gachaType' => $gachaType,
            'gachaName' => 'Character Event Wish',
            'itemType' => $origin === PullOrigin::Filler ? 'Unknown' : ($rarity >= 5 ? 'Character' : 'Weapon'),
            'itemId' => $origin === PullOrigin::Filler ? null : '',
            'name' => $name,
            'rarity' => $rarity,
            'pulledAt' => new \DateTimeImmutable($at, new \DateTimeZone('Europe/Rome')),
            'origin' => $origin,
            'declaredPity' => $declaredPity,
        ];
    }

    /**
     * Applies a plan to the rows like ManualPullService does, then reloads them like the repository:
     * stored as wall-clock digits, read back tagged with Europe/Rome, ordered by date then pull id.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function applyAndReload(array $rows, FillerPlan $plan): array
    {
        $nextId = max(array_column($rows, 'id')) + 1;
        $result = [];
        foreach ($rows as $row) {
            if (isset($plan->merges[$row['id']]) || \in_array($row['id'], $plan->fillerDeletes, true)) {
                continue;
            }
            $row['declaredPity'] = $plan->declaredPityUpdates[$row['id']] ?? $row['declaredPity'];
            $row['pulledAt'] = $plan->fillerMoves[$row['id']] ?? $row['pulledAt'];
            $result[] = $row;
        }
        foreach ($plan->fillerCreates as $slot) {
            $result[] = self::pull($nextId++, $slot->pulledAt->format('Y-m-d H:i:s'), 0, 'Unknown', PullOrigin::Filler);
        }

        foreach ($result as &$row) {
            $row['pulledAt'] = new \DateTimeImmutable($row['pulledAt']->format('Y-m-d H:i:s'), new \DateTimeZone('Europe/Rome'));
        }
        unset($row);
        usort($result, static fn (array $a, array $b): int => [$a['pulledAt']->format('Y-m-d H:i:s'), $a['pullId']] <=> [$b['pulledAt']->format('Y-m-d H:i:s'), $b['pullId']]);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private static function itemById(array $items, int $id): array
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        throw new \LogicException(sprintf('No item #%d.', $id));
    }

    public function testFillerRowsCountAsPullsButNotAsBAndResetTheAPity(): void
    {
        $rows = [
            self::pull(1, '2026-06-01 10:00:00', 3),
            self::pull(2, '2026-06-01 10:00:01', 0, 'Unknown', PullOrigin::Filler),
            self::pull(3, '2026-06-01 10:00:01', 0, 'Unknown', PullOrigin::Filler),
            self::pull(4, '2026-06-01 10:00:02', 3),
            self::pull(5, '2026-06-01 10:00:03', 4, 'Bennett'),
            self::pull(6, '2026-07-01 10:00:00', 0, 'Unknown', PullOrigin::Filler),
            self::pull(7, '2026-07-01 10:00:01', 5, 'Furina'),
        ];

        $calculator = self::calculator();
        $result = $calculator->compute($rows, self::genshinGroup('301'), self::genshinRarity());
        $summary = $result['summary'];
        $items = $result['items'];

        $this->assertSame(7, $summary['total_pulls'], 'Fillers count as pulls');
        $this->assertSame(7 * 160, $summary['currency_spent']);
        $this->assertSame(3, $summary['unknown_count']);
        $this->assertSame(2, $summary['b_count'], 'Fillers are not B');
        $this->assertSame(1, $summary['a_count']);
        $this->assertSame(7, $items[6]['pity'], 'The S pity counts the fillers');
        $this->assertSame(2, $items[4]['pity'], 'The fillers reset the A pity (5 without the reset)');
        $this->assertSame(1, $summary['a_pity'], 'Reset again by the July filler, then the S');
        $this->assertNull($items[1]['pity']);
        $this->assertSame(2, $items[1]['pull_number']);
        $this->assertSame('filler', $items[1]['origin']);
        $this->assertSame(0, $items[1]['rarity']);
        $this->assertArrayNotHasKey('has_room', $items[1]);
        $this->assertSame('recorded', $items[0]['origin']);
        $this->assertSame(['month' => '2026-07', 'total' => 2, 's_count' => 1, 'a_count' => 0], $result['monthly']['2026-07']);

        $unknown = $calculator->filterAndPaginate($items, [0], null, 1, 10);
        $this->assertSame([6, 3, 2], array_column($unknown['items'], 'id'), 'Rarity 0 selects the unknown pulls');
    }

    public function testSItemsAndTheSummaryCarryTheirWindows(): void
    {
        $rows = [
            self::pull(1, '2026-06-01 10:00:00', 3),
            self::pull(2, '2026-06-01 11:00:00', 5, 'Furina', declaredPity: 40),
            self::pull(3, '2026-06-02 10:00:00', 3),
            self::pull(4, '2026-06-02 10:00:01', 4, 'Bennett'),
        ];
        $now = new \DateTimeImmutable('2026-06-05 12:00:00', new \DateTimeZone('UTC'));

        $result = self::calculator()->compute($rows, self::genshinGroup('301'), self::genshinRarity(), $now);
        $furina = $result['items'][1];

        $this->assertSame(40, $furina['declared_pity']);
        $this->assertSame(2, $furina['recorded_pity']);
        $this->assertNull($furina['insert_after'], 'No S before: start of the history');
        $this->assertSame('2026-06-01 10:00:00', $furina['insert_before']);
        $this->assertSame('2026-06-01 09:59:00', $furina['suggested_at']);
        $this->assertTrue($furina['has_room']);
        $this->assertSame($furina['recorded_pity'], $result['summary']['recent_s_pulls'][0]['recorded_pity']);
        $this->assertSame(40, $result['summary']['recent_s_pulls'][0]['declared_pity']);
        $this->assertArrayNotHasKey('recorded_pity', $result['items'][3], 'Only S rows carry a window');
        $this->assertSame($furina, $result['summary']['oldest_s'], 'The first S item, window included');

        $this->assertSame([
            'recorded_pity' => 2,
            'declared_pity' => null,
            'insert_after' => '2026-06-01 11:00:00',
            'insert_before' => '2026-06-02 10:00:00',
            'suggested_at' => '2026-06-02 09:59:00',
            'has_room' => true,
        ], $result['summary']['open_window']);

        $empty = self::calculator()->compute([], self::genshinGroup('500'), self::genshinRarity(), $now);
        $this->assertSame('2026-06-05 12:00:00', $empty['summary']['open_window']['suggested_at'], 'Capped at "now"');
    }

    public function testTimelineEntriesUseTheCalculatorRulesAndWallClockDigits(): void
    {
        $rows = [
            self::pull(1, '2025-03-30 01:59:00', 3),
            // Recorded S with an empty item id (Genshin): matched by name
            self::pull(2, '2025-03-30 03:01:00', 5, 'Furina', declaredPity: 30),
            self::pull(3, '2025-10-26 02:30:00', 0, 'Unknown', PullOrigin::Filler),
            self::pull(4, '2025-10-26 03:00:00', 5, 'Neuvillette', PullOrigin::Manual, 12),
        ];
        $rows[2]['origin'] = 'filler';
        unset($rows[0]['origin']);

        $entries = self::calculator()->toTimelineEntries($rows, self::genshinRarity());

        $this->assertContainsOnlyInstancesOf(PullTimelineEntry::class, $entries);
        $this->assertSame([1, 2, 3, 4], array_map(static fn (PullTimelineEntry $e): int => $e->id, $entries));
        $this->assertSame(
            [PullOrigin::Recorded, PullOrigin::Recorded, PullOrigin::Filler, PullOrigin::Manual],
            array_map(static fn (PullTimelineEntry $e): PullOrigin => $e->origin, $entries),
            'Enum, string, or recorded when the column is missing',
        );
        $this->assertSame([false, true, false, true], array_map(static fn (PullTimelineEntry $e): bool => $e->isS, $entries));
        $this->assertSame([true, true, true, true], array_map(static fn (PullTimelineEntry $e): bool => $e->countsAsPull, $entries));
        $this->assertSame([null, 30, null, 12], array_map(static fn (PullTimelineEntry $e): ?int => $e->declaredPity, $entries));
        $this->assertNull($entries[1]->itemId, 'An empty item id is no item id');
        $this->assertSame('Furina', $entries[1]->name);

        // Same digits, tagged as UTC: the Rome DST changes no longer distort the spans
        $this->assertSame('UTC', $entries[0]->pulledAt->getTimezone()->getName());
        $this->assertSame('2025-03-30 01:59:00', $entries[0]->pulledAt->format('Y-m-d H:i:s'));
        $this->assertSame(62 * 60, $entries[1]->pulledAt->getTimestamp() - $entries[0]->pulledAt->getTimestamp(), 'Spring forward: 62 wall-clock minutes (2 in Rome time)');
        $this->assertSame(30 * 60, $entries[3]->pulledAt->getTimestamp() - $entries[2]->pulledAt->getTimestamp(), 'Fall back: 30 wall-clock minutes (90 in Rome time)');
    }

    /**
     * The key test: after the realign plan is applied, an S with a declared pity shows exactly that pity.
     * Genshin's Character Event Wish groups gacha types 301 and 400.
     */
    public function testRealignedRecordedSShowsTheDeclaredPity(): void
    {
        $rows = [
            self::pull(1, '2026-01-01 10:00:00', 5, 'Furina'),
            self::pull(2, '2026-01-02 10:00:00', 3, gachaType: '400'),
            self::pull(3, '2026-01-02 11:00:00', 3),
            self::pull(4, '2026-01-02 12:00:00', 4, 'Bennett', gachaType: '400'),
            self::pull(5, '2026-01-02 13:00:00', 3),
            self::pull(6, '2026-01-02 14:00:00', 3, gachaType: '400'),
            self::pull(7, '2026-01-03 10:00:00', 5, 'Neuvillette', declaredPity: 20, gachaType: '400'),
            self::pull(8, '2026-01-03 11:00:00', 3),
            self::pull(9, '2026-01-03 12:00:00', 4, 'Xingqiu', gachaType: '400'),
        ];
        $calculator = self::calculator();
        $planner = new PullGapPlanner();
        $rarity = self::genshinRarity();

        $plan = $planner->plan($calculator->toTimelineEntries($rows, $rarity));
        $this->assertCount(14, $plan->fillerCreates);
        $applied = self::applyAndReload($rows, $plan);

        $result = $calculator->compute($applied, self::genshinGroup('301'), $rarity);
        $neuvillette = self::itemById($result['items'], 7);
        $this->assertSame(20, $neuvillette['pity']);
        $this->assertSame($neuvillette['declared_pity'], $neuvillette['pity']);
        $this->assertSame(6, $neuvillette['recorded_pity'], 'Fillers are not recorded pulls');
        $this->assertSame(14, $result['summary']['unknown_count']);
        $this->assertSame(23, $result['summary']['total_pulls']);
        $this->assertSame(5, $result['summary']['b_count'], 'Rows 2, 3, 5, 6 and 8');
        $this->assertSame(3, self::itemById($result['items'], 4)['pity'], 'The fillers (before row 2) reset the A pity');

        $this->assertTrue($planner->plan($calculator->toTimelineEntries($applied, $rarity))->isEmpty(), 'A second realign changes nothing');
    }

    public function testRealignedManualSShowsTheDeclaredPity(): void
    {
        $rows = [
            self::pull(1, '2026-01-01 10:00:00', 5, 'Furina'),
            self::pull(2, '2026-01-02 10:00:00', 3),
            self::pull(3, '2026-01-02 11:00:00', 3),
            self::pull(4, '2026-01-03 10:00:00', 5, 'Neuvillette'),
            // Manual S added before the anchor's first recorded pull (id 5: inserted last)
            self::pull(5, '2026-01-02 09:59:00', 5, 'Raiden Shogun', PullOrigin::Manual, 30),
        ];
        usort($rows, static fn (array $a, array $b): int => $a['pulledAt'] <=> $b['pulledAt']);
        $calculator = self::calculator();
        $planner = new PullGapPlanner();
        $rarity = self::genshinRarity();

        $applied = self::applyAndReload($rows, $planner->plan($calculator->toTimelineEntries($rows, $rarity)));
        $result = $calculator->compute($applied, self::genshinGroup('301'), $rarity);

        $raiden = self::itemById($result['items'], 5);
        $this->assertSame(30, $raiden['pity']);
        $this->assertSame('manual', $raiden['origin']);
        $this->assertSame(3, self::itemById($result['items'], 4)['pity'], 'The anchor keeps its recorded pulls');
        $this->assertSame(29, $result['summary']['unknown_count']);
        $this->assertSame(['Neuvillette', 'Raiden Shogun', 'Furina'], array_column($result['summary']['recent_s_pulls'], 'name'));
        $this->assertSame('Furina', $result['summary']['oldest_s']['name']);
        $this->assertTrue($planner->plan($calculator->toTimelineEntries($applied, $rarity))->isEmpty());

        // Without history before it, the fillers go one second before the manual S
        $first = [self::pull(1, '2026-01-02 10:00:00', 3), self::pull(2, '2026-01-02 11:00:00', 5, 'Furina'), self::pull(3, '2026-01-01 12:00:00', 5, 'Raiden Shogun', PullOrigin::Manual, 50)];
        usort($first, static fn (array $a, array $b): int => $a['pulledAt'] <=> $b['pulledAt']);
        $applied = self::applyAndReload($first, $planner->plan($calculator->toTimelineEntries($first, $rarity)));
        $result = $calculator->compute($applied, self::genshinGroup('301'), $rarity);
        $this->assertSame(50, self::itemById($result['items'], 3)['pity']);
        $this->assertSame('2026-01-01 11:59:59', $result['items'][0]['pulled_at']);
    }
}
