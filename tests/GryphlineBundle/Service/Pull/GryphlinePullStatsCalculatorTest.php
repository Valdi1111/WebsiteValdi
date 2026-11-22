<?php

namespace App\Tests\GryphlineBundle\Service\Pull;

use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Game\RarityTierConfig;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GryphlineBundle\Service\EndfieldService;
use App\GryphlineBundle\Service\Pull\GryphlinePullStatsCalculator;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GryphlinePullStatsCalculatorTest extends TestCase
{
    private const string SPECIAL = 'E_CharacterGachaPoolType_Special';
    private const string WEAPON = 'E_WeaponGachaPoolType_Standard';

    private const array SUMMARY_KEYS = [
        'key', 'name', 'categories', 'total_pulls', 'currency_spent', 's_count', 'a_count', 'b_count',
        's_pity', 's_max_pity', 'a_pity', 'a_max_pity', 'avg_s_pity', 'avg_a_pity', 'recent_s_pulls',
        'unknown_count', 'open_window', 'oldest_s',
    ];

    private const array ITEM_KEYS = [
        'id', 'pull_number', 'seq_id', 'pool_type', 'pool_category', 'pool_name', 'gacha_name', 'item_type',
        'item_type_label', 'item_id', 'name', 'rarity', 'pity', 'pulled_at', 'origin', 'declared_pity', 'is_up',
    ];

    /** Keys only S items (and their recent_s_pulls copies) carry, after the others */
    private const array WINDOW_KEYS = ['recorded_pity', 'insert_after', 'insert_before', 'suggested_at', 'has_room'];

    /** Doctrine hydrates pulled_at (a UTC wall time) in the PHP default timezone: emulate a non-UTC one */
    private const string HYDRATION_TIMEZONE = 'Europe/Rome';

    private int $nextId = 1;
    private int $minute = 0;

    /**
     * @return array<string, mixed>
     */
    private function row(
        int $rarity,
        string $name = 'Item',
        string $poolType = self::SPECIAL,
        ?string $pulledAt = null,
        ?string $seqId = null,
        ?string $poolCategory = 'char',
        ?string $poolName = 'Winter Hunt',
        PullOrigin $origin = PullOrigin::Recorded,
        ?int $declaredPity = null,
    ): array {
        $id = $this->nextId++;
        if ($pulledAt === null) {
            $this->minute++;
            $pulledAt = sprintf('2026-06-01 %02d:%02d:00', intdiv($this->minute, 60), $this->minute % 60);
        }

        return [
            'id' => $id,
            'seqId' => $seqId ?? (string) (1000 + $id),
            'poolType' => $poolType,
            'poolCategory' => $poolCategory,
            'poolId' => 'pool_1',
            'poolName' => $poolName,
            'itemId' => 'item_' . strtolower(str_replace(' ', '_', $name)),
            'name' => $name,
            'rarity' => $rarity,
            'pulledAt' => new \DateTimeImmutable($pulledAt, new \DateTimeZone(self::HYDRATION_TIMEZONE)),
            'origin' => $origin,
            'declaredPity' => $declaredPity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filler(?string $pulledAt = null): array
    {
        $row = $this->row(0, 'Unknown', pulledAt: $pulledAt, poolName: 'Chartered Headhunting', origin: PullOrigin::Filler);
        $row['itemId'] = null;
        $row['seqId'] = 'filler_' . $row['id'];

        return $row;
    }

    /**
     * Applies a plan to array rows the way ManualPullService applies it to entities, then reads the rows back
     * as Doctrine would (same wall time, hydration timezone).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyPlan(array $rows, FillerPlan $plan): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (isset($plan->merges[$row['id']]) || \in_array($row['id'], $plan->fillerDeletes, true)) {
                continue;
            }
            $row['pulledAt'] = $plan->fillerMoves[$row['id']] ?? $row['pulledAt'];
            $row['declaredPity'] = $plan->declaredPityUpdates[$row['id']] ?? $row['declaredPity'];
            $result[] = $row;
        }
        foreach ($plan->fillerCreates as $slot) {
            $result[] = $this->filler($slot->pulledAt->format('Y-m-d H:i:s'));
        }

        foreach ($result as &$row) {
            $row['pulledAt'] = new \DateTimeImmutable($row['pulledAt']->format('Y-m-d H:i:s'), new \DateTimeZone(self::HYDRATION_TIMEZONE));
        }
        unset($row);

        return $result;
    }

    private static function utc(string $wallClock): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wallClock, new \DateTimeZone('UTC'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pulls(int $count, int $rarity = 4, string $name = 'Filler Item'): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $this->row($rarity, $name);
        }

        return $rows;
    }

    private function calculator(): GryphlinePullStatsCalculator
    {
        return new GryphlinePullStatsCalculator(new PullGapPlanner());
    }

    private function game(): EndfieldService
    {
        return new EndfieldService(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SkportSignerService::class),
            $this->createStub(SkportAuthService::class),
            $this->createStub(SkportUtilsService::class),
            $this->createStub(DenormalizerInterface::class),
            $this->createStub(ObjectMapperInterface::class),
            new NullLogger(),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function bannerGroups(): array
    {
        return $this->game()->getGachaConfig()->getBannerGroups('categories');
    }

    private function rarityConfig(bool $sResetsAPity = true): RarityConfig
    {
        $config = $this->game()->getRarityConfig();
        $this->assertTrue($config->isSResetsAPity(), 'Endfield config: an S resets the A pity');

        return $sResetsAPity ? $config : new RarityConfig(
            s: new RarityTierConfig(6, '6-Star', '6★'),
            a: new RarityTierConfig(5, '5-Star', '5★'),
            b: new RarityTierConfig(4, '4-Star', '4★'),
            pullLabel: 'Headhunts',
            pullSingularLabel: 'Headhunt',
            sResetsAPity: false,
        );
    }

    /**
     * 4 B, A, 3 B, A, 2 B, S (#12), 2 B, A, B.
     *
     * @return list<array<string, mixed>>
     */
    private function pitySequence(): array
    {
        return [
            ...$this->pulls(4),
            $this->row(5, 'Perlica'),
            ...$this->pulls(3),
            $this->row(5, 'Alesh'),
            ...$this->pulls(2),
            $this->row(6, 'Liino'),
            ...$this->pulls(2),
            $this->row(5, 'Xaihi'),
            ...$this->pulls(1),
        ];
    }

    public function testComputesSAndAPityCountsAndCurrency(): void
    {
        $bannerGroup = $this->bannerGroups()[self::SPECIAL];
        $result = $this->calculator()->compute($this->pitySequence(), $bannerGroup, $this->rarityConfig());
        $summary = $result['summary'];

        $this->assertSame(self::SUMMARY_KEYS, array_keys($summary));
        $this->assertSame(self::SPECIAL, $summary['key']);
        $this->assertSame('Chartered Headhunting', $summary['name']);
        $this->assertSame([self::SPECIAL], $summary['categories']);
        $this->assertSame(16, $summary['total_pulls']);
        $this->assertSame(8000, $summary['currency_spent'], '500 per headhunt');
        $this->assertSame(1, $summary['s_count']);
        $this->assertSame(3, $summary['a_count']);
        $this->assertSame(12, $summary['b_count']);
        $this->assertSame(4, $summary['s_pity'], 'Four headhunts since the S');
        $this->assertSame(80, $summary['s_max_pity']);
        $this->assertSame(1, $summary['a_pity'], 'One headhunt since the last A');
        $this->assertSame(10, $summary['a_max_pity']);
        $this->assertSame(12.0, $summary['avg_s_pity']);
        $this->assertSame(4.0, $summary['avg_a_pity'], '(5 + 4 + 3) / 3');

        $this->assertSame([[
            'id' => 12,
            'pull_number' => 12,
            'item_id' => 'item_liino',
            'name' => 'Liino',
            'item_type' => 'char',
            'rarity' => 6,
            'pity' => 12,
            'pulled_at' => '2026-06-01 00:12:00',
            'origin' => 'recorded',
            'declared_pity' => null,
            'is_up' => null,
            'recorded_pity' => 12,
            'insert_after' => null,
            'insert_before' => '2026-06-01 00:01:00',
            'suggested_at' => '2026-06-01 00:00:00',
            'has_room' => true,
        ]], $summary['recent_s_pulls']);
        $this->assertSame(0, $summary['unknown_count']);

        $items = $result['items'];
        $this->assertCount(16, $items);
        $this->assertSame(self::ITEM_KEYS, array_keys($items[0]));
        $this->assertSame([...self::ITEM_KEYS, ...self::WINDOW_KEYS], array_keys($items[11]), 'Window fields only on S items');
        $this->assertSame($items[11], $summary['oldest_s'], 'The first S, as a full item with its window');
        $this->assertSame(range(1, 16), array_column($items, 'pull_number'));
        $this->assertSame(
            [1, 1, 1, 1, 5, 1, 1, 1, 4, 1, 1, 12, 1, 1, 3, 1],
            array_column($items, 'pity'),
            'B rows show 1, A rows their 5★ pity, the S its 6★ pity; the S also reset the 5★ pity',
        );
    }

    public function testFiveStarPityCarriesOverAnSWhenTheRarityConfigSaysSo(): void
    {
        $bannerGroup = $this->bannerGroups()[self::SPECIAL];
        $result = $this->calculator()->compute($this->pitySequence(), $bannerGroup, $this->rarityConfig(false));

        $this->assertSame(6, $result['items'][14]['pity'], 'Six headhunts since the previous A, the S included');
        $this->assertSame(5.0, $result['summary']['avg_a_pity'], '(5 + 4 + 6) / 3');
        $this->assertSame(12, $result['summary']['recent_s_pulls'][0]['pity']);
    }

    public function testRecentSPullsAreNewestFirstAndAverageTheirPity(): void
    {
        $rows = [...$this->pulls(2), $this->row(6, 'Si'), ...$this->pulls(6), $this->row(6, 'Typhoeus', poolCategory: null)];

        $summary = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['summary'];

        $this->assertSame(['Typhoeus', 'Si'], array_column($summary['recent_s_pulls'], 'name'));
        $this->assertSame('Si', $summary['oldest_s']['name'], 'Oldest first, unlike recent_s_pulls');
        $this->assertSame(3, $summary['oldest_s']['pull_number']);
        $this->assertSame('pool_name', array_keys($summary['oldest_s'])[5], 'Full item shape, not the recent_s_pulls one');
        $this->assertSame([7, 3], array_column($summary['recent_s_pulls'], 'pity'));
        $this->assertSame('char', $summary['recent_s_pulls'][0]['item_type'], 'A missing category falls back to char');
        $this->assertSame(5.0, $summary['avg_s_pity']);
        $this->assertSame(0, $summary['s_pity']);
    }

    public function testEmptyBannerHasZeroStatsAndFloatAverages(): void
    {
        $result = $this->calculator()->compute([], $this->bannerGroups()['E_CharacterGachaPoolType_Beginner'], $this->rarityConfig());

        $this->assertSame(0, $result['summary']['total_pulls']);
        $this->assertSame(0, $result['summary']['currency_spent']);
        $this->assertSame(50, $result['summary']['s_max_pity']);
        $this->assertSame(0.0, $result['summary']['avg_s_pity']);
        $this->assertSame(0.0, $result['summary']['avg_a_pity']);
        $this->assertSame([], $result['summary']['recent_s_pulls']);
        $this->assertNull($result['summary']['oldest_s']);
        $this->assertSame([], $result['items']);
        $this->assertSame([], $result['monthly']);
    }

    public function testRowsAreOrderedByPulledAtThenNumericSeqId(): void
    {
        $tenPull = '2026-06-01 10:00:00';
        $rows = [
            $this->row(4, 'Ten', pulledAt: $tenPull, seqId: '10'),
            $this->row(6, 'Hundred', pulledAt: $tenPull, seqId: '100'),
            $this->row(4, 'Nine', pulledAt: $tenPull, seqId: '9'),
            $this->row(4, 'Earlier', pulledAt: '2026-06-01 09:59:59', seqId: '500'),
            $this->row(4, 'Synthetic', pulledAt: $tenPull, seqId: 'import_7f3a'),
        ];

        $calculator = $this->calculator();
        $this->assertSame(
            ['Earlier', 'Synthetic', 'Nine', 'Ten', 'Hundred'],
            array_column($calculator->sortRows($rows), 'name'),
            'Integer order of seqId: a non-numeric seqId counts as 0',
        );

        $result = $calculator->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());
        $this->assertSame(['Earlier', 'Synthetic', 'Nine', 'Ten', 'Hundred'], array_column($result['items'], 'name'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($result['items'], 'pull_number'));
        $this->assertSame(5, $result['summary']['recent_s_pulls'][0]['pity']);
    }

    /**
     * @param list<array{group: array<string, mixed>, rows: list<array<string, mixed>>}> $grouped
     * @return array<string, array{group: array<string, mixed>, rows: list<array<string, mixed>>}>
     */
    private static function byKey(array $grouped): array
    {
        $byKey = [];
        foreach ($grouped as $entry) {
            $byKey[(string) $entry['group']['key']] = $entry;
        }

        return $byKey;
    }

    public function testGroupRowsByBannerKeepsConfiguredGroupsAndAddsDynamicOnes(): void
    {
        $bannerGroups = $this->bannerGroups();
        // Input deliberately not in chronological order: groups are created in order of first headhunt
        $rows = [
            $this->row(4, 'Late collab', 'E_CharacterGachaPoolType_Collab', '2026-06-05 10:00:00', poolName: 'Second Collab Name'),
            $this->row(4, 'Weapon', self::WEAPON, '2026-06-02 10:00:00', poolCategory: 'weapon', poolName: 'Deep Cold Issue'),
            $this->row(4, 'Legacy weapon', '', '2026-06-04 10:00:00', poolCategory: 'weapon', poolName: null),
            $this->row(6, 'Early collab', 'E_CharacterGachaPoolType_Collab', '2026-06-03 10:00:00', poolName: 'Collab Hunt'),
            $this->row(4, 'Special', self::SPECIAL, '2026-06-01 10:00:00'),
        ];

        $list = $this->calculator()->groupRowsByBanner($rows, $bannerGroups);

        $this->assertTrue(array_is_list($list));
        $this->assertSame([
            self::SPECIAL,
            'E_CharacterGachaPoolType_Standard',
            self::WEAPON,
            'E_CharacterGachaPoolType_Beginner',
            'E_CharacterGachaPoolType_Collab',
            'weapon',
        ], array_map(static fn (array $entry): string => $entry['group']['key'], $list));
        $grouped = self::byKey($list);

        $this->assertSame($bannerGroups[self::SPECIAL], $grouped[self::SPECIAL]['group']);
        $this->assertSame(['Special'], array_column($grouped[self::SPECIAL]['rows'], 'name'));
        $this->assertSame([], $grouped['E_CharacterGachaPoolType_Standard']['rows']);
        $this->assertSame(['Weapon'], array_column($grouped[self::WEAPON]['rows'], 'name'));

        $this->assertSame([
            'key' => 'E_CharacterGachaPoolType_Collab',
            'name' => 'Collab Hunt',
            'categories' => ['E_CharacterGachaPoolType_Collab'],
            's_max_pity' => 80,
            'a_max_pity' => 10,
        ], $grouped['E_CharacterGachaPoolType_Collab']['group'], 'Named after the first pool, default pity limits');
        $this->assertSame(['Early collab', 'Late collab'], array_column($grouped['E_CharacterGachaPoolType_Collab']['rows'], 'name'));

        $this->assertSame('Headhunting weapon', $grouped['weapon']['group']['name'], 'Without a pool type or a pool name');
        $this->assertSame(['weapon'], $grouped['weapon']['group']['categories']);

        $legacy = $this->calculator()->compute($grouped['weapon']['rows'], $grouped['weapon']['group'], $this->rarityConfig());
        $this->assertSame('Headhunting weapon', $legacy['items'][0]['pool_name'], 'Rows without a pool name show the banner name');
        $this->assertSame('Headhunting weapon', $legacy['items'][0]['gacha_name']);
    }

    public function testGroupRowsByBannerWithoutConfiguredGroupsGroupsByPoolType(): void
    {
        $rows = [
            $this->row(4, 'Weapon', self::WEAPON, '2026-06-02 10:00:00', poolCategory: 'weapon', poolName: 'Deep Cold Issue'),
            $this->row(4, 'Special', self::SPECIAL, '2026-06-01 10:00:00'),
            $this->row(4, 'Numeric', '301', '2026-06-03 10:00:00', poolName: null),
        ];

        $list = $this->calculator()->groupRowsByBanner($rows, []);

        $this->assertSame([self::SPECIAL, self::WEAPON, '301'], array_map(static fn (array $entry): string => $entry['group']['key'], $list), 'Numeric-looking keys stay strings');
        $this->assertSame('Winter Hunt', $list[0]['group']['name']);
        $this->assertSame('Deep Cold Issue', $list[1]['group']['name']);
        $this->assertSame('Headhunting 301', $list[2]['group']['name']);
        $this->assertSame(['301'], $list[2]['group']['categories']);
    }

    public function testMonthlyCountsHeadhuntsAndSAndAPerMonth(): void
    {
        $rows = [
            $this->row(4, pulledAt: '2026-05-30 10:00:00'),
            $this->row(5, pulledAt: '2026-05-30 10:00:01'),
            $this->row(6, pulledAt: '2026-05-31 23:59:59'),
            $this->row(4, pulledAt: '2026-05-31 23:59:59'),
            $this->row(4, pulledAt: '2026-06-01 00:00:00'),
            $this->row(6, pulledAt: '2026-06-02 00:00:00'),
        ];

        $monthly = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['monthly'];

        $this->assertSame([
            '2026-05' => ['month' => '2026-05', 'total' => 4, 's_count' => 1, 'a_count' => 1],
            '2026-06' => ['month' => '2026-06', 'total' => 2, 's_count' => 1, 'a_count' => 0],
        ], $monthly);
    }

    public function testFilterAndPaginateListsNewestFirst(): void
    {
        $rows = [
            ...$this->pulls(3),
            $this->row(5, 'Perlica'),
            ...$this->pulls(3),
            $this->row(6, 'Liino'),
            $this->row(5, 'Alesh'),
            ...$this->pulls(2),
            $this->row(5, 'Perlica'),
        ];
        $calculator = $this->calculator();
        $items = $calculator->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['items'];

        $firstPage = $calculator->filterAndPaginate($items, [], null, 1, 5);
        $this->assertSame(12, $firstPage['total']);
        $this->assertSame([12, 11, 10, 9, 8], array_column($firstPage['items'], 'pull_number'));

        $lastPage = $calculator->filterAndPaginate($items, [], null, 3, 5);
        $this->assertSame([2, 1], array_column($lastPage['items'], 'pull_number'));

        $beyond = $calculator->filterAndPaginate($items, [], null, 9, 5);
        $this->assertSame(['items' => [], 'total' => 12], $beyond);

        $sAndA = $calculator->filterAndPaginate($items, [6, 5], null, 1, 15);
        $this->assertSame(4, $sAndA['total']);
        $this->assertSame(['Perlica', 'Alesh', 'Liino', 'Perlica'], array_column($sAndA['items'], 'name'));

        $search = $calculator->filterAndPaginate($items, [], '  PERL ', 1, 15);
        $this->assertSame(2, $search['total'], 'Case-insensitive, trimmed substring match');
        $this->assertSame([12, 4], array_column($search['items'], 'pull_number'));

        $combined = $calculator->filterAndPaginate($items, [6], 'li', 1, 15);
        $this->assertSame(['Liino'], array_column($combined['items'], 'name'));

        $bOnly = $calculator->filterAndPaginate($items, [4], null, 2, 5);
        $this->assertSame(8, $bOnly['total']);
        $this->assertSame([3, 2, 1], array_column($bOnly['items'], 'pull_number'));
    }

    public function testRateUpFlagIsExposedWhereTheRowHasOne(): void
    {
        $rows = [$this->row(4), $this->row(6, 'Liino'), $this->row(6, 'Si')];
        $rows[1]['isUp'] = true;
        $rows[2]['isUp'] = false;

        $result = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());

        $this->assertSame([null, true, false], array_column($result['items'], 'is_up'));
        $this->assertSame([false, true], array_column($result['summary']['recent_s_pulls'], 'is_up'));
    }

    public function testItemsCarryRawCategoryAndItsLabel(): void
    {
        $rows = [
            $this->row(4, 'Operator', poolCategory: 'char'),
            $this->row(4, 'Weapon', self::WEAPON, poolCategory: 'weapon'),
            $this->row(4, 'Gift', poolCategory: 'gift', poolName: ''),
            $this->row(4, 'Unknown', poolCategory: ''),
        ];

        $items = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['items'];

        $this->assertSame(['Operator', 'Weapon', 'Gift', null], array_column($items, 'item_type_label'));
        $this->assertSame(['char', 'weapon', 'gift', ''], array_column($items, 'item_type'));
        $this->assertSame('Chartered Headhunting', $items[2]['pool_name'], 'An empty pool name falls back to the banner name');
        $this->assertSame('Winter Hunt', $items[0]['gacha_name']);
        $this->assertSame([
            'id' => 2,
            'pull_number' => 2,
            'seq_id' => '1002',
            'pool_type' => self::WEAPON,
            'pool_category' => 'weapon',
            'pool_name' => 'Winter Hunt',
            'gacha_name' => 'Winter Hunt',
            'item_type' => 'weapon',
            'item_type_label' => 'Weapon',
            'item_id' => 'item_weapon',
            'name' => 'Weapon',
            'rarity' => 4,
            'pity' => 1,
            'pulled_at' => '2026-06-01 00:02:00',
            'origin' => 'recorded',
            'declared_pity' => null,
            'is_up' => null,
        ], $items[1]);
        $this->assertNull(GryphlinePullStatsCalculator::getItemTypeLabel(null));
    }

    public function testRowPredicates(): void
    {
        $calculator = $this->calculator();
        $rarityConfig = $this->rarityConfig();

        $this->assertTrue($calculator->isS($this->row(6), $rarityConfig));
        $this->assertTrue($calculator->isS($this->row(7), $rarityConfig), 'Anything at or above the S tier');
        $this->assertFalse($calculator->isS($this->row(5), $rarityConfig));
        $this->assertFalse($calculator->isS(['rarity' => null], $rarityConfig));

        $this->assertTrue($calculator->countsAsPull($this->row(4)));
        $this->assertTrue($calculator->countsAsPull($this->row(6)));
    }

    public function testFillerRowsCountAsPullsButNotAsBAndResetTheFiveStarPity(): void
    {
        $rows = [
            ...$this->pulls(2),
            $this->row(5, 'Perlica'),
            $this->filler(),
            $this->filler(),
            $this->filler(),
            ...$this->pulls(2),
            $this->row(5, 'Alesh'),
        ];

        $calculator = $this->calculator();
        $result = $calculator->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());
        $summary = $result['summary'];

        $this->assertSame(9, $summary['total_pulls'], 'Fillers are headhunts');
        $this->assertSame(4500, $summary['currency_spent']);
        $this->assertSame(3, $summary['unknown_count']);
        $this->assertSame(4, $summary['b_count'], 'Fillers are not B');
        $this->assertSame(2, $summary['a_count']);
        $this->assertSame(9, $summary['s_pity'], 'Fillers advance the S pity');
        $this->assertSame(0, $summary['a_pity']);
        $this->assertSame([1, 1, 3, null, null, null, 1, 1, 3], array_column($result['items'], 'pity'), 'The A after the fillers counts from them, not from the previous A');
        $this->assertSame(range(1, 9), array_column($result['items'], 'pull_number'));
        $this->assertSame(['filler', 'filler', 'filler'], array_column(\array_slice($result['items'], 3, 3), 'origin'));
        $this->assertSame(3.0, $summary['avg_a_pity']);
        $this->assertSame(['month' => '2026-06', 'total' => 9, 's_count' => 0, 'a_count' => 2], $result['monthly']['2026-06']);

        $unknown = $calculator->filterAndPaginate($result['items'], [0], null, 1, 15);
        $this->assertSame(3, $unknown['total'], 'Rarity 0 lists the unknown pulls');
        $this->assertSame([6, 5, 4], array_column($unknown['items'], 'pull_number'));
    }

    public function testSItemsCarryTheirWindowAndTheSummaryTheOpenWindow(): void
    {
        $rows = [
            $this->row(4, pulledAt: '2026-06-01 10:00:00'),
            $this->row(6, 'Liino', pulledAt: '2026-06-01 12:00:00', declaredPity: 2),
            $this->row(4, pulledAt: '2026-06-02 10:00:00'),
            $this->row(4, pulledAt: '2026-06-02 10:00:00'),
            $this->row(6, 'Si', pulledAt: '2026-06-02 10:00:00'),
            $this->row(4, pulledAt: '2026-06-03 10:00:00'),
        ];

        $result = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig(), self::utc('2026-06-05 08:00:00'));
        $items = $result['items'];

        $this->assertSame([
            'recorded_pity' => 2,
            'insert_after' => null,
            'insert_before' => '2026-06-01 10:00:00',
            'suggested_at' => '2026-06-01 09:59:00',
            'has_room' => true,
        ], array_intersect_key($items[1], array_flip(self::WINDOW_KEYS)));
        $this->assertSame(2, $items[1]['declared_pity']);
        $this->assertSame([
            'recorded_pity' => 3,
            'insert_after' => '2026-06-01 12:00:00',
            'insert_before' => '2026-06-02 10:00:00',
            'suggested_at' => '2026-06-02 09:59:00',
            'has_room' => true,
        ], array_intersect_key($items[4], array_flip(self::WINDOW_KEYS)));
        $this->assertArrayNotHasKey('has_room', $items[0], 'Only S items carry a window');
        $this->assertSame(['Si', 'Liino'], array_column($result['summary']['recent_s_pulls'], 'name'));
        $this->assertSame('2026-06-02 09:59:00', $result['summary']['recent_s_pulls'][0]['suggested_at']);

        $this->assertSame([
            'recorded_pity' => 1,
            'declared_pity' => null,
            'insert_after' => '2026-06-02 10:00:00',
            'insert_before' => '2026-06-03 10:00:00',
            'suggested_at' => '2026-06-03 09:59:00',
            'has_room' => true,
        ], $result['summary']['open_window']);
    }

    public function testOpenWindowSuggestionNeverPassesNowInTheWallClock(): void
    {
        $rows = [$this->row(6, 'Liino', pulledAt: '2026-06-01 12:00:00')];

        // 12:30 UTC wall time; the row is hydrated in Europe/Rome, where 12:00 is 10:00 UTC
        $summary = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig(), self::utc('2026-06-01 12:30:00'))['summary'];

        $this->assertSame('2026-06-01 12:30:00', $summary['open_window']['suggested_at'], 'Capped at now, compared as UTC wall times');
        $this->assertNull($summary['open_window']['insert_before']);
    }

    public function testToTimelineEntriesFollowTheCalculatorOrderAndRules(): void
    {
        $rows = [
            $this->row(6, 'Manual', pulledAt: '2026-06-01 09:00:00', seqId: 'manual_x', origin: PullOrigin::Manual, declaredPity: 7),
            $this->row(6, 'Liino', pulledAt: '2026-06-01 10:00:00', seqId: '12'),
            $this->row(4, 'Item', pulledAt: '2026-06-01 10:00:00', seqId: '9'),
            $this->filler('2026-06-01 08:59:59'),
        ];
        $rows[0]['itemId'] = null;

        $entries = $this->calculator()->toTimelineEntries($rows, $this->rarityConfig());

        $this->assertContainsOnlyInstancesOf(PullTimelineEntry::class, $entries);
        $this->assertSame([4, 1, 3, 2], array_map(static fn (PullTimelineEntry $entry): int => $entry->id, $entries));
        $this->assertSame([PullOrigin::Filler, PullOrigin::Manual, PullOrigin::Recorded, PullOrigin::Recorded], array_map(static fn (PullTimelineEntry $entry): PullOrigin => $entry->origin, $entries));
        $this->assertSame([false, true, false, true], array_map(static fn (PullTimelineEntry $entry): bool => $entry->isS, $entries));
        $this->assertSame([true, true, true, true], array_map(static fn (PullTimelineEntry $entry): bool => $entry->countsAsPull, $entries));
        $this->assertSame(7, $entries[1]->declaredPity);
        $this->assertNull($entries[1]->itemId);
        $this->assertSame('item_liino', $entries[3]->itemId);
        $this->assertSame('Liino', $entries[3]->name);
        $this->assertSame('UTC', $entries[3]->pulledAt->getTimezone()->getName());
        $this->assertSame('2026-06-01 10:00:00', $entries[3]->pulledAt->format('Y-m-d H:i:s'), 'Same wall time as stored');
    }

    public function testDeclaredPityAddsUpAfterThePlanIsApplied(): void
    {
        $rows = [
            ...$this->pulls(2),
            $this->row(6, 'Liino', declaredPity: 10),
            ...$this->pulls(3),
            $this->row(5, 'Perlica'),
            $this->row(6, 'Si', declaredPity: 9),
            $this->row(4),
        ];
        $calculator = $this->calculator();
        $planner = new PullGapPlanner();
        $bannerGroup = $this->bannerGroups()[self::SPECIAL];

        $plan = $planner->plan($calculator->toTimelineEntries($rows, $this->rarityConfig()));
        $this->assertCount(7 + 4, $plan->fillerCreates, '10 - 3 before Liino, 9 - 5 before Si');
        $rows = $this->applyPlan($rows, $plan);
        $this->assertTrue($planner->plan($calculator->toTimelineEntries($rows, $this->rarityConfig()))->isEmpty(), 'Idempotent');

        $result = $calculator->compute($rows, $bannerGroup, $this->rarityConfig());
        $sItems = array_values(array_filter($result['items'], static fn (array $item): bool => $item['rarity'] === 6));
        $this->assertSame([10, 9], array_column($sItems, 'pity'), 'pity === declared_pity');
        $this->assertSame([10, 9], array_column($sItems, 'declared_pity'));
        $this->assertSame([3, 5], array_column($sItems, 'recorded_pity'));
        $this->assertSame(11, $result['summary']['unknown_count']);
        $this->assertSame(20, $result['summary']['total_pulls']);
        $this->assertSame(1, $result['summary']['s_pity']);
        $this->assertSame(1, $result['summary']['a_pity']);
        $perlica = array_values(array_filter($result['items'], static fn (array $item): bool => $item['name'] === 'Perlica'))[0];
        $this->assertSame(4, $perlica['pity'], 'Equal gaps: the fillers of Si go right after Liino, and reset the A pity');
    }

    public function testManualSPityAddsUpAfterThePlanIsApplied(): void
    {
        $rows = [
            ...$this->pulls(2),
            $this->row(6, 'Liino'),
            $this->row(4),
        ];
        // Added later (higher id) before the first recorded pull, like ManualPullService::createManualS()
        $rows[] = $this->row(6, 'Typhoeus', pulledAt: '2026-05-31 20:00:00', seqId: 'manual_1', origin: PullOrigin::Manual, declaredPity: 6);
        $calculator = $this->calculator();
        $planner = new PullGapPlanner();

        $rows = $this->applyPlan($rows, $planner->plan($calculator->toTimelineEntries($rows, $this->rarityConfig())));
        $this->assertTrue($planner->plan($calculator->toTimelineEntries($rows, $this->rarityConfig()))->isEmpty());

        $result = $calculator->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());
        $this->assertSame(['Unknown', 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'Typhoeus'], array_column(\array_slice($result['items'], 0, 6), 'name'));
        $manual = $result['items'][5];
        $this->assertSame(['manual', 6, 6], [$manual['origin'], $manual['pity'], $manual['declared_pity']]);
        $this->assertSame(1, $manual['recorded_pity']);
        $this->assertSame(3, $result['items'][8]['pity'], 'Liino keeps its recorded pity');
        $this->assertSame(['Liino', 'Typhoeus'], array_column($result['summary']['recent_s_pulls'], 'name'));
        $this->assertSame('2026-05-31 19:59:59', $result['items'][0]['pulled_at'], 'One second before the manual S: the start of the history');
    }

    public function testFillersNeverShareASecondWithAnotherRow(): void
    {
        // Ten-pulls share a second; gaps of 0, 1 and 2+ seconds around the S
        $rows = [
            $this->row(4, pulledAt: '2026-06-01 10:00:00', seqId: '1'),
            $this->row(4, pulledAt: '2026-06-01 10:00:00', seqId: '2'),
            $this->row(6, 'Liino', pulledAt: '2026-06-01 10:00:00', seqId: '3'),
            $this->row(4, pulledAt: '2026-06-01 10:00:01', seqId: '4'),
            $this->row(4, pulledAt: '2026-06-01 10:00:04', seqId: '5'),
            $this->row(4, pulledAt: '2026-06-01 10:00:04', seqId: '6'),
            $this->row(6, 'Si', pulledAt: '2026-06-01 10:00:04', seqId: '7', declaredPity: 12),
        ];
        $rows[0]['declaredPity'] = null;
        $rows[2]['declaredPity'] = 8;
        $calculator = $this->calculator();

        $rows = $this->applyPlan($rows, (new PullGapPlanner())->plan($calculator->toTimelineEntries($rows, $this->rarityConfig())));

        $recordedSeconds = [];
        $fillerSeconds = [];
        foreach ($rows as $row) {
            $second = $row['pulledAt']->format('Y-m-d H:i:s');
            if ($row['origin'] === PullOrigin::Filler) {
                $fillerSeconds[] = $second;
            } else {
                $recordedSeconds[$second] = true;
            }
        }
        $this->assertCount(5 + 8, $fillerSeconds);
        foreach ($fillerSeconds as $second) {
            $this->assertArrayNotHasKey($second, $recordedSeconds, $second);
        }

        $items = $calculator->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['items'];
        $sPities = array_column(array_values(array_filter($items, static fn (array $item): bool => $item['rarity'] === 6)), 'pity');
        $this->assertSame([8, 12], $sPities, 'The numeric seqId order of the ten-pulls is kept around the fillers');
    }
}
