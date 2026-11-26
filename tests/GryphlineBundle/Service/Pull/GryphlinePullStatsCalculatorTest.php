<?php

namespace App\Tests\GryphlineBundle\Service\Pull;

use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Game\RarityTierConfig;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use App\GryphlineBundle\Service\Pull\GryphlinePullStatsCalculator;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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
        'unknown_count', 'open_window', 'oldest_s', 'pools', 'pity_pool_name', 'allow_add_s',
    ];

    private const array ITEM_KEYS = [
        'id', 'pull_number', 'seq_id', 'pool_type', 'pool_category', 'pool_name', 'gacha_name', 'item_type',
        'item_type_label', 'item_id', 'name', 'rarity', 'pity', 'pulled_at', 'origin', 'declared_pity', 'is_up',
        'kind', 'is_free', 'pool_version', 'bonus_label',
    ];

    /** Keys only S items (and their recent_s_pulls copies) carry, after the others */
    private const array WINDOW_KEYS = ['recorded_pity', 'insert_after', 'insert_before', 'suggested_at', 'has_room'];

    /** Catalog pools of the rate-up tests: one rate-up operator (and a 5★), two for the joint banner, none for the standard one */
    private const array RATE_UP_POOLS = [
        'special_1_0_3' => ['id' => 'special_1_0_3', 'category' => 'char', 'type' => 0, 'up' => ['chr_0013_aglina', 'chr_0005_chen']],
        'joint_1_2_2' => ['id' => 'joint_1_2_2', 'category' => 'char', 'type' => 3, 'up' => ['chr_0016_laevat', 'chr_0013_aglina']],
        'standard' => ['id' => 'standard', 'category' => 'char', 'type' => 2, 'up' => []],
    ];

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
        string $kind = GryphlinePull::KIND_DRAW,
        bool $isFree = false,
        ?string $poolId = 'pool_1',
        ?int $poolVersion = null,
        ?string $gachaTs = null,
        // Null: derived from the name
        ?string $itemId = null,
        ?bool $isUp = null,
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
            'poolId' => $poolId,
            'poolName' => $poolName,
            'itemId' => $itemId ?? 'item_' . strtolower(str_replace(' ', '_', $name)),
            'name' => $name,
            'rarity' => $rarity,
            'pulledAt' => new \DateTimeImmutable($pulledAt, new \DateTimeZone(self::HYDRATION_TIMEZONE)),
            'gachaTs' => $gachaTs,
            'origin' => $origin,
            'declaredPity' => $declaredPity,
            'isUp' => $isUp,
            'kind' => $kind,
            'isFree' => $isFree,
            'poolVersion' => $poolVersion,
        ];
    }

    /**
     * Bonus entry (Headhunting Dossier, Tribute...): not a pull, with or without a rarity and an item.
     *
     * @return array<string, mixed>
     */
    private function bonus(
        string $kind,
        ?int $rarity = null,
        string $name = 'Headhunting Dossier',
        ?string $itemId = null,
        ?string $pulledAt = null,
        string $poolType = self::SPECIAL,
        ?string $poolCategory = 'char',
        ?string $poolId = 'pool_1',
        ?string $poolName = 'Winter Hunt',
    ): array {
        $row = $this->row($rarity ?? 0, $name, $poolType, $pulledAt, poolCategory: $poolCategory, poolName: $poolName, kind: $kind, poolId: $poolId);
        $row['rarity'] = $rarity;
        $row['itemId'] = $itemId;

        return $row;
    }

    /**
     * One free Urgent Recruitment ten-pull: rows sharing pool, gachaTs and second, in seqId order.
     *
     * @param list<int> $rarities One per row
     * @param array<int, string> $names Names by row index (others are "Free Item")
     * @return list<array<string, mixed>>
     */
    private function freeTenPull(array $rarities, string $pulledAt, string $gachaTs, array $names = [], string $poolId = 'pool_1'): array
    {
        $rows = [];
        foreach ($rarities as $index => $rarity) {
            $rows[] = $this->row($rarity, $names[$index] ?? 'Free Item', pulledAt: $pulledAt, isFree: true, poolId: $poolId, gachaTs: $gachaTs);
        }

        return $rows;
    }

    /**
     * Stored catalog with the given pools (save() adds an empty pool list when $pools is null, so a catalog
     * stored before the pools is written raw) and items besides Ember.
     *
     * @param array<string, array<string, mixed>>|null $pools
     * @param array<string, array{id: string, name: string, rarity: ?int, type: string}> $items
     */
    private static function catalog(?array $pools, array $items = []): AkeCatalog
    {
        $stored = ['version' => '1.6.4@11944529-13', 'built_at' => '2026-11-23T06:00:00+01:00', 'items' => [
            'chr_0009_azrila' => ['id' => 'chr_0009_azrila', 'name' => 'Ember', 'rarity' => 6, 'type' => 'char'],
        ] + $items];
        $adapter = new ArrayAdapter();
        if ($pools === null) {
            $adapter->save($adapter->getItem(AkeCatalog::CACHE_KEY)->set($stored));

            return new AkeCatalog($adapter);
        }

        $catalog = new AkeCatalog($adapter);
        $catalog->save($stored + ['pools' => $pools]);
        $catalog->reset();

        return $catalog;
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

    private function calculator(?AkeCatalog $catalog = null): GryphlinePullStatsCalculator
    {
        return new GryphlinePullStatsCalculator(new PullGapPlanner(), $catalog);
    }

    private function game(): ArknightsEndfieldService
    {
        return new ArknightsEndfieldService(
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
            'kind' => 'draw',
            'is_free' => false,
            'pool_version' => null,
            'bonus_label' => null,
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
            'E_CharacterGachaPoolType_Rerun',
            'E_CharacterGachaPoolType_Joint',
            'E_CharacterGachaPoolType_Standard',
            self::WEAPON,
            'E_WeaponGachaPoolType_Rerun',
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

    public function testAnOfficialSCountsLikeAManualS(): void
    {
        $rows = [$this->row(4), $this->row(4), $this->row(6, 'Liino', declaredPity: 3), $this->row(4)];
        $rows[2]['origin'] = 'official';

        $result = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());

        $this->assertSame('official', $result['items'][2]['origin'], 'Not read back as a recorded row');
        $this->assertSame(3, $result['items'][2]['pity']);
        $this->assertSame(1, $result['summary']['s_count']);
        $this->assertSame(['official'], array_column($result['summary']['recent_s_pulls'], 'origin'));
        $this->assertSame(PullOrigin::Official, $this->calculator()->toTimelineEntries($rows, $this->rarityConfig())[2]->origin);
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
            'kind' => 'draw',
            'is_free' => false,
            'pool_version' => null,
            'bonus_label' => null,
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

    /**
     * Pity of the named items (S and A rows), in item order.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, ?int>
     */
    private static function pityByName(array $items, string ...$names): array
    {
        $pities = [];
        foreach ($items as $item) {
            if (\in_array($item['name'], $names, true)) {
                $pities[$item['name']] = $item['pity'];
            }
        }

        return $pities;
    }

    /**
     * 3 paid B (May), two free ten-pulls (June: a 5★ at the 10th row, then a 6★ and a 5★), then paid
     * B, B, A, S, B (July).
     *
     * @return list<array<string, mixed>>
     */
    private function paidAndFreeSequence(): array
    {
        return [
            $this->row(4, pulledAt: '2026-05-31 09:00:00'),
            $this->row(4, pulledAt: '2026-05-31 09:01:00'),
            $this->row(4, pulledAt: '2026-05-31 09:02:00'),
            ...$this->freeTenPull([4, 4, 4, 4, 4, 4, 4, 4, 4, 5], '2026-06-01 10:00:00', '1780308000000', [9 => 'Free Perlica']),
            ...$this->freeTenPull([4, 4, 6, 4, 5, 4, 4, 4, 4, 4], '2026-06-01 10:05:00', '1780308300000', [2 => 'Free Liino', 4 => 'Free Alesh']),
            $this->row(4, pulledAt: '2026-07-01 09:00:00'),
            $this->row(4, pulledAt: '2026-07-01 09:01:00'),
            $this->row(5, 'Perlica', pulledAt: '2026-07-01 09:02:00'),
            $this->row(6, 'Si', pulledAt: '2026-07-01 09:03:00'),
            $this->row(4, pulledAt: '2026-07-01 09:04:00'),
        ];
    }

    public function testFreePullsNeverTouchThePaidCountersTotalsOrAverages(): void
    {
        $result = $this->calculator()->compute($this->paidAndFreeSequence(), $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());
        $summary = $result['summary'];
        $items = $result['items'];

        $this->assertSame(8, $summary['total_pulls'], 'Paid headhunts only');
        $this->assertSame(4000, $summary['currency_spent']);
        $this->assertSame(1, $summary['s_pity'], 'One paid headhunt since the paid S');
        $this->assertSame(1, $summary['a_pity']);
        $this->assertSame(7.0, $summary['avg_s_pity'], 'Paid S only');
        $this->assertSame(6.0, $summary['avg_a_pity'], 'Paid A only');
        $this->assertSame(
            ['Perlica' => 6, 'Si' => 7],
            self::pityByName($items, 'Perlica', 'Si'),
            'The free 5★ and 6★ neither advance nor reset the paid counters',
        );
        $this->assertSame([1, 2, 3, ...array_fill(0, 20, null), 4, 5, 6, 7, 8], array_column($items, 'pull_number'));
        $this->assertSame([...array_fill(0, 3, false), ...array_fill(0, 20, true), ...array_fill(0, 5, false)], array_column($items, 'is_free'));
    }

    public function testEachFreeTenPullHasItsOwnCounters(): void
    {
        $items = $this->calculator()->compute($this->paidAndFreeSequence(), $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['items'];

        $this->assertSame(
            ['Free Perlica' => 10, 'Free Liino' => 3, 'Free Alesh' => 2],
            self::pityByName($items, 'Free Perlica', 'Free Liino', 'Free Alesh'),
            'Counted from the first row of each ten-pull: not from the paid pulls, not from the previous free ten-pull',
        );

        // Same gachaTs and second, another pool: another ten-pull
        $rows = [
            ...$this->freeTenPull(array_fill(0, 9, 4), '2026-06-02 10:00:00', '1780394400000'),
            ...$this->freeTenPull([5], '2026-06-02 10:00:00', '1780394400000', ['Other Pool A'], 'pool_2'),
        ];
        $items = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['items'];
        $this->assertSame(['Other Pool A' => 1], self::pityByName($items, 'Other Pool A'));
    }

    public function testFreeSAndACountButStayOutOfTheMonthlyTotal(): void
    {
        $result = $this->calculator()->compute($this->paidAndFreeSequence(), $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());

        $this->assertSame(2, $result['summary']['s_count'], 'Paid and free S');
        $this->assertSame(3, $result['summary']['a_count'], 'Paid and free A');
        $this->assertSame([
            '2026-05' => ['month' => '2026-05', 'total' => 3, 's_count' => 0, 'a_count' => 0],
            '2026-06' => ['month' => '2026-06', 'total' => 0, 's_count' => 1, 'a_count' => 2],
            '2026-07' => ['month' => '2026-07', 'total' => 5, 's_count' => 1, 'a_count' => 1],
        ], $result['monthly']);
    }

    public function testFreeSIsARecentSWithoutWindowAndNeverTheOldestS(): void
    {
        $summary = $this->calculator()->compute($this->paidAndFreeSequence(), $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['summary'];

        $this->assertSame(['Si', 'Free Liino'], array_column($summary['recent_s_pulls'], 'name'));
        $free = $summary['recent_s_pulls'][1];
        $this->assertSame([
            'id' => 16,
            'pull_number' => null,
            'item_id' => 'item_free_liino',
            'name' => 'Free Liino',
            'item_type' => 'char',
            'rarity' => 6,
            'pity' => 3,
            'pulled_at' => '2026-06-01 10:05:00',
            'origin' => 'recorded',
            'declared_pity' => null,
            'is_up' => null,
            'kind' => 'draw',
            'is_free' => true,
            'pool_version' => null,
            'bonus_label' => null,
        ], $free, 'No window keys on a free S');
        $this->assertSame([...array_keys($free), ...self::WINDOW_KEYS], array_keys($summary['recent_s_pulls'][0]), 'The paid S keeps its window');

        $this->assertSame('Si', $summary['oldest_s']['name'], 'The older free S is not the anchor of "Add missing S"');
        $this->assertSame(7, $summary['oldest_s']['recorded_pity'], 'The free 6★ does not close the window of the paid S');
        $this->assertNull($summary['oldest_s']['insert_after']);

        $onlyFree = $this->calculator()->compute(
            $this->freeTenPull([4, 6, 4, 4, 4, 4, 4, 4, 4, 5], '2026-06-02 10:00:00', '1780394400000', [1 => 'Lone Free S']),
            $this->bannerGroups()[self::SPECIAL],
            $this->rarityConfig(),
        )['summary'];
        $this->assertNull($onlyFree['oldest_s']);
        $this->assertSame(['Lone Free S'], array_column($onlyFree['recent_s_pulls'], 'name'));
        $this->assertSame(0, $onlyFree['total_pulls']);
        $this->assertSame([0, 0], [$onlyFree['s_pity'], $onlyFree['a_pity']]);
        $this->assertSame([0.0, 0.0], [$onlyFree['avg_s_pity'], $onlyFree['avg_a_pity']]);
    }

    /**
     * Bonus 6★ first (own month), then paid B, B, Dossier, A, unknown-kind bonus, B, S, then a bonus 6★ again.
     *
     * @return list<array<string, mixed>>
     */
    private function paidAndBonusSequence(): array
    {
        return [
            $this->bonus('gift_weapon', 6, 'Delivery Guaranteed', 'wpn_funnel_0011', '2026-05-31 08:00:00', poolId: 'special_1_0_3'),
            $this->row(4, pulledAt: '2026-06-01 09:00:00', poolId: 'special_1_0_3'),
            $this->row(4, pulledAt: '2026-06-01 09:01:00', poolId: 'special_1_0_3'),
            $this->bonus('gift_intel_book', pulledAt: '2026-06-01 09:02:00', poolId: 'special_1_0_3'),
            $this->row(5, 'Perlica', pulledAt: '2026-06-01 09:03:00', poolId: 'special_1_0_3'),
            $this->bonus('some_new_kind', name: 'Mystery Box', pulledAt: '2026-06-01 09:04:00', poolId: 'special_1_0_3'),
            $this->row(4, pulledAt: '2026-06-01 09:05:00', poolId: 'special_1_0_3'),
            $this->row(6, 'Aglina', pulledAt: '2026-06-01 09:06:00', poolId: 'special_1_0_3', itemId: 'chr_0013_aglina'),
            $this->bonus('gift_weapon', 6, 'Delivery Guaranteed', 'wpn_funnel_0011', '2026-06-01 09:07:00', poolId: 'special_1_0_3'),
        ];
    }

    public function testBonusEntriesAreListedWithALabelAndNoPity(): void
    {
        // The weapon of the Tribute is a rate-up item too: a bonus 6★ would read as up if it were a pull
        $catalog = self::catalog(['special_1_0_3' => ['id' => 'special_1_0_3', 'category' => 'char', 'type' => 0, 'up' => ['chr_0013_aglina', 'wpn_funnel_0011']]]);
        $items = $this->calculator($catalog)->compute($this->paidAndBonusSequence(), $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['items'];

        $this->assertSame(['Tribute', null, null, 'Headhunting Dossier', null, 'Some new kind', null, null, 'Tribute'], array_column($items, 'bonus_label'));
        $this->assertSame(['gift_weapon', 'draw', 'draw', 'gift_intel_book', 'draw', 'some_new_kind', 'draw', 'draw', 'gift_weapon'], array_column($items, 'kind'));
        $this->assertSame([null, 1, 2, null, 3, null, 4, 5, null], array_column($items, 'pull_number'));
        $this->assertSame([null, 1, 1, null, 3, null, 1, 5, null], array_column($items, 'pity'), 'The paid A and S count only the paid headhunts');
        $this->assertSame([6, 4, 4, null, 5, null, 4, 6, 6], array_column($items, 'rarity'), 'Rarity null when the entry has none');
        $this->assertSame([null, null, null, null, null, null, null, true, null], array_column($items, 'is_up'), 'Only the paid S reads the catalog');
        $this->assertSame(array_fill(0, 9, false), array_column($items, 'is_free'));
        $this->assertSame(self::ITEM_KEYS, array_keys($items[0]), 'A bonus 6★ carries no window');
        $this->assertSame(self::ITEM_KEYS, array_keys($items[8]));
        $this->assertNull($items[3]['item_id']);

        $this->assertSame('Headhunting Dossier', GryphlinePull::getBonusLabel('gift_intel_book'));
        $this->assertSame('Tribute', GryphlinePull::getBonusLabel('gift_weapon'));
        $this->assertSame('Some new kind', GryphlinePull::getBonusLabel('some_new_kind'), 'Unknown kinds get a readable label');
    }

    public function testBonusEntriesNeverTouchCountersTotalsOrMonthly(): void
    {
        $result = $this->calculator()->compute($this->paidAndBonusSequence(), $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());
        $summary = $result['summary'];

        $this->assertSame(5, $summary['total_pulls']);
        $this->assertSame(2500, $summary['currency_spent']);
        $this->assertSame([1, 1, 3], [$summary['s_count'], $summary['a_count'], $summary['b_count']], 'Bonus entries are neither S, A nor B');
        $this->assertSame(0, $summary['unknown_count']);
        $this->assertSame(0, $summary['s_pity'], 'The bonus 6★ after the S does not advance the pity');
        $this->assertSame(0, $summary['a_pity']);
        $this->assertSame(5.0, $summary['avg_s_pity']);
        $this->assertSame(3.0, $summary['avg_a_pity']);
        $this->assertSame(['Aglina'], array_column($summary['recent_s_pulls'], 'name'));
        $this->assertSame('Aglina', $summary['oldest_s']['name']);
        $this->assertSame(5, $summary['oldest_s']['recorded_pity']);
        $this->assertNull($summary['oldest_s']['insert_after'], 'The bonus 6★ before it does not close a window');
        $this->assertSame(
            ['2026-06' => ['month' => '2026-06', 'total' => 5, 's_count' => 1, 'a_count' => 1]],
            $result['monthly'],
            'A month with bonus entries only is not listed',
        );
    }

    public function testRarityFilterNeverHidesBonusEntriesButTheSearchDoes(): void
    {
        $rows = [
            $this->filler(),
            $this->row(4, 'Item'),
            $this->row(6, 'Liino'),
            $this->bonus('gift_intel_book'),
            $this->row(5, 'Perlica'),
            $this->bonus('gift_weapon', 6, 'Delivery Guaranteed', 'wpn_funnel_0011'),
        ];
        $calculator = $this->calculator();
        $items = $calculator->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['items'];
        $names = static fn (array $page): array => array_column($page['items'], 'name');

        $this->assertSame(['Delivery Guaranteed', 'Perlica', 'Headhunting Dossier', 'Liino', 'Item', 'Unknown'], $names($calculator->filterAndPaginate($items, [], null, 1, 15)));
        $this->assertSame(['Delivery Guaranteed', 'Headhunting Dossier', 'Liino'], $names($calculator->filterAndPaginate($items, [6], null, 1, 15)));
        $this->assertSame(['Delivery Guaranteed', 'Perlica', 'Headhunting Dossier', 'Liino'], $names($calculator->filterAndPaginate($items, [5, 6], null, 1, 15)));
        $this->assertSame(['Delivery Guaranteed', 'Headhunting Dossier', 'Unknown'], $names($calculator->filterAndPaginate($items, [0], null, 1, 15)), 'Not unknown pulls, but still listed');
        $this->assertSame(3, $calculator->filterAndPaginate($items, [0], null, 1, 15)['total']);

        $this->assertSame(['Headhunting Dossier'], $names($calculator->filterAndPaginate($items, [6], 'dossier', 1, 15)));
        $this->assertSame(['Liino'], $names($calculator->filterAndPaginate($items, [6], 'liino', 1, 15)), 'The search drops the bonus entries');
        $this->assertSame(['Headhunting Dossier'], $names($calculator->filterAndPaginate($items, [], ' DOSSIER ', 1, 15)));
    }

    public function testTimelineEntriesLeaveFreeAndBonusSOut(): void
    {
        $rows = [
            $this->row(4, pulledAt: '2026-06-01 10:00:00'),
            $this->row(6, 'Free Liino', pulledAt: '2026-06-01 10:01:00', isFree: true, gachaTs: '1780308060000'),
            $this->bonus('gift_weapon', 6, 'Delivery Guaranteed', 'wpn_funnel_0011', '2026-06-01 10:02:00'),
            $this->row(6, 'Si', pulledAt: '2026-06-01 10:03:00'),
            $this->row(4, pulledAt: '2026-06-01 10:04:00'),
        ];
        $calculator = $this->calculator();

        $entries = $calculator->toTimelineEntries($rows, $this->rarityConfig());
        $this->assertSame([false, false, false, true, false], array_map(static fn (PullTimelineEntry $entry): bool => $entry->isS, $entries));
        $this->assertSame([true, false, false, true, true], array_map(static fn (PullTimelineEntry $entry): bool => $entry->countsAsPull, $entries));

        $si = $calculator->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['items'][3];
        $this->assertSame(['Si', 2, 2, null], [$si['name'], $si['pity'], $si['recorded_pity'], $si['insert_after']], 'The window of the paid S spans the free and bonus 6★');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function perBannerPityGroups(): iterable
    {
        yield 'Special Headhunting' => ['E_CharacterGachaPoolType_Joint', 'char'];
        yield 'Arsenal Issue' => ['E_WeaponGachaPoolType_Standard', 'weapon'];
        yield 'RE-Factor Issue' => ['E_WeaponGachaPoolType_Rerun', 'weapon'];
    }

    /**
     * Two interleaved banners of one per-banner pity group, given newest first; the last row is a bonus entry of
     * banner A, after the last paid pull (banner B).
     *
     * @return list<array<string, mixed>>
     */
    private function interleavedPools(string $poolType, string $category): array
    {
        $a = ['poolType' => $poolType, 'poolCategory' => $category, 'poolId' => 'pool_a', 'poolName' => 'Banner A'];
        $b = ['poolType' => $poolType, 'poolCategory' => $category, 'poolId' => 'pool_b', 'poolName' => 'Banner B'];

        return array_reverse([
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:00:00'),
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:01:00'),
            $this->row(4, ...$b, pulledAt: '2026-06-01 10:02:00'),
            $this->row(6, 'Liino', ...$a, pulledAt: '2026-06-01 10:03:00'),
            $this->row(4, ...$b, pulledAt: '2026-06-01 10:04:00'),
            $this->row(5, 'Perlica', ...$b, pulledAt: '2026-06-01 10:05:00'),
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:06:00'),
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:07:00'),
            $this->row(4, ...$b, pulledAt: '2026-06-01 10:08:00'),
            $this->bonus('gift_intel_book', pulledAt: '2026-06-01 10:09:00', poolType: $poolType, poolCategory: $category, poolId: 'pool_a', poolName: 'Banner A'),
        ]);
    }

    #[DataProvider('perBannerPityGroups')]
    public function testPerBannerPityGroupsCountEachPoolOnItsOwn(string $groupKey, string $category): void
    {
        $calculator = $this->calculator();
        $bannerGroup = $this->bannerGroups()[$groupKey];
        $rows = $this->interleavedPools($groupKey, $category);

        $result = $calculator->compute($rows, $bannerGroup, $this->rarityConfig());
        $summary = $result['summary'];

        $this->assertSame(self::SUMMARY_KEYS, array_keys($summary));
        $this->assertSame([1, 1, 1, 3, 1, 3, 1, 1, 1, null], array_column($result['items'], 'pity'), 'The S counts banner A only, the A banner B only');
        $this->assertSame([...range(1, 9), null], array_column($result['items'], 'pull_number'), 'Pull numbers stay global');
        $this->assertSame(9, $summary['total_pulls']);
        $this->assertSame(4, $summary['s_pity'], 'Banner B, the one of the last paid pull');
        $this->assertSame(1, $summary['a_pity']);
        $this->assertSame('Banner B', $summary['pity_pool_name']);
        $this->assertFalse($summary['allow_add_s']);
        $this->assertSame([
            [
                'pool_id' => 'pool_a',
                'pool_name' => 'Banner A',
                'pool_version' => null,
                'total_pulls' => 5,
                's_count' => 1,
                's_pity' => 2,
                'a_pity' => 2,
                'first_pulled_at' => '2026-06-01 10:00:00',
                'last_pulled_at' => '2026-06-01 10:07:00',
                'start_at' => null,
                'end_at' => null,
                'featured' => [],
            ],
            [
                'pool_id' => 'pool_b',
                'pool_name' => 'Banner B',
                'pool_version' => null,
                'total_pulls' => 4,
                's_count' => 0,
                's_pity' => 4,
                'a_pity' => 1,
                'first_pulled_at' => '2026-06-01 10:02:00',
                'last_pulled_at' => '2026-06-01 10:08:00',
                'start_at' => null,
                'end_at' => null,
                'featured' => [],
            ],
        ], $summary['pools']);

        $this->assertSame(3, $summary['oldest_s']['recorded_pity'], 'The missing-pulls window of the S spans banner A only');
        $this->assertSame(4, $summary['open_window']['recorded_pity'], 'Open window of banner B');
        $this->assertNull($summary['open_window']['insert_after']);

        $this->assertTrue($calculator->isPoolPityGroup($bannerGroup));
        $partitions = $calculator->partitionByPityPool($rows, $bannerGroup);
        $this->assertSame(['pool_a', 'pool_b'], array_keys($partitions));
        $minutes = static fn (array $poolRows): array => array_map(static fn (array $row): string => $row['pulledAt']->format('i'), $poolRows);
        $this->assertSame(['00', '01', '03', '06', '07', '09'], $minutes($partitions['pool_a']), 'Chronological order inside a banner, bonus entry included');
        $this->assertSame(['02', '04', '05', '08'], $minutes($partitions['pool_b']));
    }

    public function testReFactorIssueCountsEachRerunOnItsOwn(): void
    {
        $groupKey = 'E_WeaponGachaPoolType_Rerun';
        $x = ['poolType' => $groupKey, 'poolCategory' => 'weapon', 'poolId' => 'rerun_wpn_x', 'poolName' => 'Aglina Issue'];
        $y = ['poolType' => $groupKey, 'poolCategory' => 'weapon', 'poolId' => 'rerun_wpn_y', 'poolName' => 'Ember Issue'];
        $rows = [
            $this->row(4, ...$x, pulledAt: '2026-06-01 10:00:00', poolVersion: 1),
            $this->row(4, ...$x, pulledAt: '2026-06-01 10:01:00', poolVersion: 1),
            $this->row(6, 'Delivery Guaranteed', ...$x, pulledAt: '2026-06-01 10:02:00', poolVersion: 1),
            $this->row(4, ...$x, pulledAt: '2026-06-01 10:03:00', poolVersion: 1),
            $this->row(4, ...$y, pulledAt: '2026-06-01 10:04:00'),
            $this->row(4, ...$y, pulledAt: '2026-06-01 10:05:00'),
            $this->row(4, ...$x, pulledAt: '2026-06-01 10:06:00', poolVersion: 2),
            $this->row(5, 'Seeker', ...$x, pulledAt: '2026-06-01 10:07:00', poolVersion: 2),
            $this->row(6, 'Grand Vision', ...$y, pulledAt: '2026-06-01 10:08:00'),
            $this->row(4, ...$x, pulledAt: '2026-06-01 10:09:00', poolVersion: 2),
        ];
        $calculator = $this->calculator();
        $bannerGroup = $this->bannerGroups()[$groupKey];

        $result = $calculator->compute($rows, $bannerGroup, $this->rarityConfig());
        $summary = $result['summary'];

        $this->assertSame([1, 1, 3, 1, 1, 1, 1, 2, 3, 1], array_column($result['items'], 'pity'), 'Rerun #2 starts again; the run without a version spans its interleaved rows');
        $this->assertSame([1, 1, 1, 1, null, null, 2, 2, null, 2], array_column($result['items'], 'pool_version'));
        $this->assertSame(3, $summary['s_pity'], 'Rerun #2 only, not continued from rerun #1');
        $this->assertSame(1, $summary['a_pity']);
        $this->assertSame('Aglina Issue #2', $summary['pity_pool_name']);
        $this->assertFalse($summary['allow_add_s']);
        $this->assertSame([
            [
                'pool_id' => 'rerun_wpn_x',
                'pool_name' => 'Aglina Issue',
                'pool_version' => 1,
                'total_pulls' => 4,
                's_count' => 1,
                's_pity' => 1,
                'a_pity' => 1,
                'first_pulled_at' => '2026-06-01 10:00:00',
                'last_pulled_at' => '2026-06-01 10:03:00',
                'start_at' => null,
                'end_at' => null,
                'featured' => [],
            ],
            [
                'pool_id' => 'rerun_wpn_y',
                'pool_name' => 'Ember Issue',
                'pool_version' => null,
                'total_pulls' => 3,
                's_count' => 1,
                's_pity' => 0,
                'a_pity' => 0,
                'first_pulled_at' => '2026-06-01 10:04:00',
                'last_pulled_at' => '2026-06-01 10:08:00',
                'start_at' => null,
                'end_at' => null,
                'featured' => [],
            ],
            [
                'pool_id' => 'rerun_wpn_x',
                'pool_name' => 'Aglina Issue',
                'pool_version' => 2,
                'total_pulls' => 3,
                's_count' => 0,
                's_pity' => 3,
                'a_pity' => 1,
                'first_pulled_at' => '2026-06-01 10:06:00',
                'last_pulled_at' => '2026-06-01 10:09:00',
                'start_at' => null,
                'end_at' => null,
                'featured' => [],
            ],
        ], $summary['pools']);

        $this->assertSame(['rerun_wpn_x#1', 'rerun_wpn_y', 'rerun_wpn_x#2'], array_keys($calculator->partitionByPityPool($rows, $bannerGroup)));
        $this->assertSame('rerun_wpn_x#2', GryphlinePullStatsCalculator::pityPoolKey(['poolId' => 'rerun_wpn_x', 'poolVersion' => 2]));
        $this->assertSame('rerun_wpn_x', GryphlinePullStatsCalculator::pityPoolKey(['poolId' => 'rerun_wpn_x', 'poolVersion' => null]));
        $this->assertSame('rerun_wpn_x', GryphlinePullStatsCalculator::pityPoolKey(['poolId' => 'rerun_wpn_x', 'poolVersion' => 0]), 'Version 0 means no rerun number');
        $this->assertSame('', GryphlinePullStatsCalculator::pityPoolKey(['poolId' => null]));
    }

    public function testPoolsCarryTheRunOfTheirBannerFromTheCatalog(): void
    {
        $run1 = ['start_at' => '2026-09-24 17:00:00', 'end_at' => '2026-10-14 16:59:59'];
        $run2 = ['start_at' => '2026-11-25 03:00:00', 'end_at' => '2026-12-16 16:59:59'];
        $catalog = self::catalog([
            // Stored out of order: the catalog sorts the runs by start
            'rerun_wpn_aglina' => ['id' => 'rerun_wpn_aglina', 'category' => 'weapon', 'type' => 1, 'up' => ['wpn_funnel_0011'], 'up_source' => 'irminsul', 'runs' => [$run2, $run1]],
            'weponbox_1_0_3' => ['id' => 'weponbox_1_0_3', 'category' => 'weapon', 'type' => 0, 'up' => ['wpn_pistol_0010'], 'up_source' => 'irminsul', 'runs' => [$run1]],
            // Stored before the irminsul.gg banners
            'weponbox_1_0_1' => ['id' => 'weponbox_1_0_1', 'category' => 'weapon', 'type' => 0, 'up' => ['wpn_sword_0006']],
        ]);

        $groupKey = 'E_WeaponGachaPoolType_Rerun';
        $rerun = ['poolType' => $groupKey, 'poolCategory' => 'weapon', 'poolId' => 'rerun_wpn_aglina', 'poolName' => 'Aglina Issue'];
        $rows = [
            $this->row(4, ...$rerun, pulledAt: '2026-09-25 10:00:00', poolVersion: 1),
            $this->row(4, ...$rerun, pulledAt: '2026-11-26 10:00:00', poolVersion: 2),
            // Rerun #3 is not in the catalog yet
            $this->row(4, ...$rerun, pulledAt: '2027-05-01 10:00:00', poolVersion: 3),
            // Without rerun number: the first run
            $this->row(4, ...$rerun, pulledAt: '2027-05-02 10:00:00'),
            $this->row(4, poolType: $groupKey, pulledAt: '2027-05-03 10:00:00', poolCategory: 'weapon', poolId: 'rerun_wpn_unknown'),
            $this->row(4, poolType: $groupKey, pulledAt: '2027-05-04 10:00:00', poolCategory: 'weapon', poolId: null),
        ];

        $pools = $this->calculator($catalog)->compute($rows, $this->bannerGroups()[$groupKey], $this->rarityConfig())['summary']['pools'];

        $this->assertSame(['pool_id', 'pool_name', 'pool_version', 'total_pulls', 's_count', 's_pity', 'a_pity', 'first_pulled_at', 'last_pulled_at', 'start_at', 'end_at', 'featured'], array_keys($pools[0]));
        $this->assertSame([
            ['rerun_wpn_aglina', 1, $run1['start_at'], $run1['end_at']],
            ['rerun_wpn_aglina', 2, $run2['start_at'], $run2['end_at']],
            ['rerun_wpn_aglina', 3, null, null],
            ['rerun_wpn_aglina', null, $run1['start_at'], $run1['end_at']],
            ['rerun_wpn_unknown', null, null, null],
            [null, null, null, null],
        ], array_map(static fn (array $pool): array => [$pool['pool_id'], $pool['pool_version'], $pool['start_at'], $pool['end_at']], $pools));

        // Other pools: their only run, whatever the rerun number of the row
        $groupKey = self::WEAPON;
        $rows = [
            $this->row(4, poolType: $groupKey, pulledAt: '2026-02-25 10:00:00', poolCategory: 'weapon', poolId: 'weponbox_1_0_3', poolVersion: 2),
            $this->row(4, poolType: $groupKey, pulledAt: '2026-02-26 10:00:00', poolCategory: 'weapon', poolId: 'weponbox_1_0_1'),
        ];

        $pools = $this->calculator($catalog)->compute($rows, $this->bannerGroups()[$groupKey], $this->rarityConfig())['summary']['pools'];

        $this->assertSame([
            ['weponbox_1_0_3', $run1['start_at'], $run1['end_at']],
            ['weponbox_1_0_1', null, null],
        ], array_map(static fn (array $pool): array => [$pool['pool_id'], $pool['start_at'], $pool['end_at']], $pools));

        // RE-Factor Headhunting (one pity across its pools) has the per-banner breakdown too
        $catalog = self::catalog([
            'rerun_chr_aglina' => ['id' => 'rerun_chr_aglina', 'category' => 'char', 'type' => 4, 'up' => ['chr_0013_aglina'], 'up_source' => 'irminsul', 'runs' => [$run1, $run2]],
        ]);
        $rows = [$this->row(4, poolType: 'E_CharacterGachaPoolType_Rerun', pulledAt: '2026-11-26 10:00:00', poolId: 'rerun_chr_aglina', poolVersion: 2)];

        $pool = $this->calculator($catalog)->compute($rows, $this->bannerGroups()['E_CharacterGachaPoolType_Rerun'], $this->rarityConfig())['summary']['pools'][0];

        $this->assertSame([$run2['start_at'], $run2['end_at']], [$pool['start_at'], $pool['end_at']]);
    }

    public function testPoolsTakeTheRunContainingTheirFirstPull(): void
    {
        // irminsul.gg lacks run #1 of this rerun pool: its only run is run #2
        $run2 = ['start_at' => '2026-11-25 03:00:00', 'end_at' => '2026-12-16 16:59:59'];
        $catalog = self::catalog([
            'rerun_wpn_aglina' => ['id' => 'rerun_wpn_aglina', 'category' => 'weapon', 'type' => 1, 'up' => ['wpn_funnel_0011'], 'up_source' => 'irminsul', 'runs' => [$run2]],
        ]);

        $groupKey = 'E_WeaponGachaPoolType_Rerun';
        $rerun = ['poolType' => $groupKey, 'poolCategory' => 'weapon', 'poolId' => 'rerun_wpn_aglina', 'poolName' => 'Aglina Issue'];
        $rows = [
            // Outside every run: the run of its rerun number, the first one
            $this->row(4, ...$rerun, pulledAt: '2026-09-25 10:00:00', poolVersion: 1),
            // Half an hour into the only run (UTC wall clock): that run, whatever its rerun number says
            $this->row(4, ...$rerun, pulledAt: '2026-11-25 03:30:00', poolVersion: 2),
            // Only the first pull of the entry is looked up
            $this->row(4, ...$rerun, pulledAt: '2026-12-20 10:00:00', poolVersion: 2),
            // Outside every run, rerun number beyond the stored runs: no run
            $this->row(4, ...$rerun, pulledAt: '2027-05-01 10:00:00', poolVersion: 3),
            // Outside every run, no rerun number: the first run
            $this->row(4, ...$rerun, pulledAt: '2027-05-02 10:00:00'),
        ];

        $pools = $this->calculator($catalog)->compute($rows, $this->bannerGroups()[$groupKey], $this->rarityConfig())['summary']['pools'];

        $this->assertSame([
            [1, $run2['start_at'], $run2['end_at']],
            [2, $run2['start_at'], $run2['end_at']],
            [3, null, null],
            [null, $run2['start_at'], $run2['end_at']],
        ], array_map(static fn (array $pool): array => [$pool['pool_version'], $pool['start_at'], $pool['end_at']], $pools));
    }

    public function testPoolsListTheRateUpItemsOfTheirBannerFromTheCatalog(): void
    {
        $catalog = self::catalog([
            // Unknown ids are skipped, the others keep the catalog order
            'joint_1_2_2' => ['id' => 'joint_1_2_2', 'category' => 'char', 'type' => 3, 'up' => ['chr_0016_laevat', 'chr_0099_unknown', 'chr_0013_aglina', 'chr_0025_ardelia'], 'up_source' => 'irminsul', 'runs' => []],
            'joint_1_3_1' => ['id' => 'joint_1_3_1', 'category' => 'char', 'type' => 3, 'up' => [], 'up_source' => 'akedata', 'runs' => []],
            'rerun_wpn_aglina' => ['id' => 'rerun_wpn_aglina', 'category' => 'weapon', 'type' => 1, 'up' => ['wpn_funnel_0011'], 'up_source' => 'irminsul', 'runs' => []],
        ], [
            'chr_0013_aglina' => ['id' => 'chr_0013_aglina', 'name' => 'Gilberta', 'rarity' => 6, 'type' => 'char'],
            'chr_0016_laevat' => ['id' => 'chr_0016_laevat', 'name' => 'Laevatain', 'rarity' => 6, 'type' => 'char'],
            // No rarity in the catalog: the rate-up items of a banner are its 6★
            'chr_0025_ardelia' => ['id' => 'chr_0025_ardelia', 'name' => 'Ardelia', 'rarity' => null, 'type' => 'char'],
            'wpn_funnel_0011' => ['id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'rarity' => 6, 'type' => 'weapon'],
        ]);

        $groupKey = 'E_CharacterGachaPoolType_Joint';
        $rows = [
            $this->row(4, poolType: $groupKey, pulledAt: '2026-05-15 10:00:00', poolId: 'joint_1_2_2'),
            $this->row(6, 'Gilberta', poolType: $groupKey, pulledAt: '2026-05-15 10:01:00', poolId: 'joint_1_2_2', itemId: 'chr_0013_aglina'),
            $this->row(4, poolType: $groupKey, pulledAt: '2026-06-15 10:00:00', poolId: 'joint_1_3_1'),
            $this->row(4, poolType: $groupKey, pulledAt: '2026-07-15 10:00:00', poolId: 'joint_9_9_9'),
            $this->row(4, poolType: $groupKey, pulledAt: '2026-08-15 10:00:00', poolId: null),
        ];

        $pools = $this->calculator($catalog)->compute($rows, $this->bannerGroups()[$groupKey], $this->rarityConfig())['summary']['pools'];

        $this->assertSame(['joint_1_2_2', 'joint_1_3_1', 'joint_9_9_9', null], array_column($pools, 'pool_id'));
        $this->assertSame([
            ['item_id' => 'chr_0016_laevat', 'name' => 'Laevatain', 'item_type' => 'char', 'rarity' => 6],
            ['item_id' => 'chr_0013_aglina', 'name' => 'Gilberta', 'item_type' => 'char', 'rarity' => 6],
            ['item_id' => 'chr_0025_ardelia', 'name' => 'Ardelia', 'item_type' => 'char', 'rarity' => 6],
        ], $pools[0]['featured']);
        $this->assertSame([], $pools[1]['featured'], 'Pool without rate-up items');
        $this->assertSame([], $pools[2]['featured'], 'Unknown pool');
        $this->assertSame([], $pools[3]['featured'], 'Entry without pool id');

        // Every run of a rerun pool features the same items; the weapon type is the catalog one
        $groupKey = 'E_WeaponGachaPoolType_Rerun';
        $rerun = ['poolType' => $groupKey, 'poolCategory' => 'weapon', 'poolId' => 'rerun_wpn_aglina', 'poolName' => 'Aglina Issue'];
        $rows = [
            $this->row(4, ...$rerun, pulledAt: '2026-09-25 10:00:00', poolVersion: 1),
            $this->row(4, ...$rerun, pulledAt: '2026-11-26 10:00:00', poolVersion: 2),
        ];

        $pools = $this->calculator($catalog)->compute($rows, $this->bannerGroups()[$groupKey], $this->rarityConfig())['summary']['pools'];

        $deliveryGuaranteed = [['item_id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'item_type' => 'weapon', 'rarity' => 6]];
        $this->assertSame([$deliveryGuaranteed, $deliveryGuaranteed], array_column($pools, 'featured'));

        // Without a catalog (or before any catalog was stored) no pool features anything
        $this->assertSame([[], []], array_column($this->calculator()->compute($rows, $this->bannerGroups()[$groupKey], $this->rarityConfig())['summary']['pools'], 'featured'));
        $this->assertSame([[], []], array_column($this->calculator(new AkeCatalog(new ArrayAdapter()))->compute($rows, $this->bannerGroups()[$groupKey], $this->rarityConfig())['summary']['pools'], 'featured'));
    }

    public function testOnlyThePerBannerPityGroupsArePartitionedByPool(): void
    {
        $calculator = $this->calculator();
        $bannerGroups = $this->bannerGroups();

        foreach ($bannerGroups as $key => $group) {
            $this->assertSame(\in_array($key, ArknightsEndfieldService::POOL_PITY_BANNERS, true), $calculator->isPoolPityGroup($group), $key);
        }
        $this->assertSame(
            ['E_CharacterGachaPoolType_Joint', 'E_WeaponGachaPoolType_Standard', 'E_WeaponGachaPoolType_Rerun'],
            array_values(array_filter(array_keys($bannerGroups), static fn (string $key): bool => $calculator->isPoolPityGroup($bannerGroups[$key]))),
        );

        $rows = [
            $this->row(4, 'Second', pulledAt: '2026-06-01 10:01:00', poolId: 'special_b'),
            $this->row(4, 'First', pulledAt: '2026-06-01 10:00:00', poolId: 'special_a'),
        ];
        $shared = $calculator->partitionByPityPool($rows, $bannerGroups[self::SPECIAL]);
        $this->assertSame([''], array_keys($shared));
        $this->assertSame(['First', 'Second'], array_column($shared[''], 'name'));
        $this->assertSame(['' => []], $calculator->partitionByPityPool([], $bannerGroups['E_CharacterGachaPoolType_Joint']));
    }

    public function testReFactorHeadhuntingSharesOnePityAcrossItsPools(): void
    {
        $groupKey = 'E_CharacterGachaPoolType_Rerun';
        $a = ['poolType' => $groupKey, 'poolId' => 'rerun_a', 'poolName' => 'Laevatain Rerun'];
        $b = ['poolType' => $groupKey, 'poolId' => 'rerun_b', 'poolName' => 'Ember Rerun'];
        $rows = [
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:00:00'),
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:01:00'),
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:02:00'),
            $this->row(6, 'Laevatain', ...$a, pulledAt: '2026-06-01 10:03:00'),
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:04:00'),
            // A free pull is not in the total of its banner
            $this->row(4, 'Free Item', ...$a, pulledAt: '2026-06-01 10:04:30', isFree: true, gachaTs: '1780308270000'),
            $this->row(4, ...$a, pulledAt: '2026-06-01 10:05:00'),
            $this->row(4, ...$b, pulledAt: '2026-06-01 10:06:00'),
            $this->row(4, ...$b, pulledAt: '2026-06-01 10:07:00'),
            $this->row(4, ...$b, pulledAt: '2026-06-01 10:08:00'),
            $this->row(6, 'Ember', ...$b, pulledAt: '2026-06-01 10:09:00'),
            $this->row(4, ...$b, pulledAt: '2026-06-01 10:10:00'),
        ];
        $calculator = $this->calculator();
        $bannerGroup = $this->bannerGroups()[$groupKey];

        $summary = $calculator->compute($rows, $bannerGroup, $this->rarityConfig())['summary'];

        $this->assertSame(['Ember', 'Laevatain'], array_column($summary['recent_s_pulls'], 'name'));
        $this->assertSame([6, 4], array_column($summary['recent_s_pulls'], 'pity'), 'Ember continues the pity of the first rerun banner');
        $this->assertSame([6, '2026-06-01 10:03:00'], [$summary['recent_s_pulls'][0]['recorded_pity'], $summary['recent_s_pulls'][0]['insert_after']], 'One window across the banners');
        $this->assertSame(1, $summary['s_pity']);
        $this->assertSame(1, $summary['a_pity']);
        $this->assertSame(11, $summary['total_pulls']);
        $this->assertNull($summary['pity_pool_name']);
        $this->assertTrue($summary['allow_add_s']);
        $this->assertSame([
            [
                'pool_id' => 'rerun_a',
                'pool_name' => 'Laevatain Rerun',
                'pool_version' => null,
                'total_pulls' => 6,
                's_count' => 1,
                's_pity' => null,
                'a_pity' => null,
                'first_pulled_at' => '2026-06-01 10:00:00',
                'last_pulled_at' => '2026-06-01 10:05:00',
                'start_at' => null,
                'end_at' => null,
                'featured' => [],
            ],
            [
                'pool_id' => 'rerun_b',
                'pool_name' => 'Ember Rerun',
                'pool_version' => null,
                'total_pulls' => 5,
                's_count' => 1,
                's_pity' => null,
                'a_pity' => null,
                'first_pulled_at' => '2026-06-01 10:06:00',
                'last_pulled_at' => '2026-06-01 10:10:00',
                'start_at' => null,
                'end_at' => null,
                'featured' => [],
            ],
        ], $summary['pools']);

        $this->assertFalse($calculator->isPoolPityGroup($bannerGroup));
        $this->assertSame([''], array_keys($calculator->partitionByPityPool($rows, $bannerGroup)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sharedPityGroups(): iterable
    {
        yield 'Basic Headhunting' => ['E_CharacterGachaPoolType_Standard'];
        yield 'Beginner Headhunting' => ['E_CharacterGachaPoolType_Beginner'];
    }

    #[DataProvider('sharedPityGroups')]
    public function testSharedPityGroupsHaveNoPoolBreakdown(string $groupKey): void
    {
        $rows = [
            $this->row(4, poolType: $groupKey, poolId: 'pool_a'),
            $this->row(4, poolType: $groupKey, poolId: 'pool_a'),
            $this->row(4, poolType: $groupKey, poolId: 'pool_b'),
            $this->row(6, 'Liino', poolType: $groupKey, poolId: 'pool_b'),
            $this->row(4, poolType: $groupKey, poolId: 'pool_b'),
        ];

        $summary = $this->calculator()->compute($rows, $this->bannerGroups()[$groupKey], $this->rarityConfig())['summary'];

        $this->assertSame([4], array_column($summary['recent_s_pulls'], 'pity'), 'One pity across the banners');
        $this->assertSame(1, $summary['s_pity']);
        $this->assertSame([], $summary['pools']);
        $this->assertNull($summary['pity_pool_name']);
        $this->assertTrue($summary['allow_add_s']);
    }

    public function testCharteredListsItsBannersWithTheSharedPity(): void
    {
        $rows = [
            $this->row(4, poolType: self::SPECIAL, poolId: 'pool_a'),
            $this->row(4, poolType: self::SPECIAL, poolId: 'pool_a'),
            $this->row(4, poolType: self::SPECIAL, poolId: 'pool_b'),
            $this->row(6, 'Liino', poolType: self::SPECIAL, poolId: 'pool_b'),
            $this->row(4, poolType: self::SPECIAL, poolId: 'pool_b'),
        ];

        $summary = $this->calculator()->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig())['summary'];

        $this->assertSame([4], array_column($summary['recent_s_pulls'], 'pity'), 'One pity across the banners');
        $this->assertSame(1, $summary['s_pity']);
        $this->assertSame(['pool_a', 'pool_b'], array_column($summary['pools'], 'pool_id'), 'Each banner listed, oldest first');
        $this->assertSame([2, 3], array_column($summary['pools'], 'total_pulls'));
        $this->assertSame([0, 1], array_column($summary['pools'], 's_count'));
        $this->assertSame([null, null], array_column($summary['pools'], 's_pity'), 'The pity is shared, never per banner');
        $this->assertNull($summary['pity_pool_name']);
        $this->assertTrue($summary['allow_add_s'], 'Earlier S can still be added: the pity is shared');
    }

    public function testRateUpFlagOfAnSComesFromTheCatalogWhenNotStored(): void
    {
        $aglina = ['poolId' => 'special_1_0_3', 'itemId' => 'chr_0013_aglina'];
        $ember = ['poolId' => 'special_1_0_3', 'itemId' => 'chr_0009_azrila'];
        $rows = [
            $this->row(6, 'Aglina', ...$aglina),
            $this->row(6, 'Ember', ...$ember),
            $this->row(6, 'Ember stored up', ...$ember, isUp: true),
            $this->row(6, 'Aglina stored not up', ...$aglina, isUp: false),
            $this->row(6, 'Free Aglina', ...$aglina, isFree: true, gachaTs: '1780308000000'),
            $this->row(6, 'Free Ember', ...$ember, isFree: true, gachaTs: '1780308060000'),
            $this->row(5, 'Chen Qianyu', poolId: 'special_1_0_3', itemId: 'chr_0005_chen'),
            $this->row(4, 'Up B', poolId: 'special_1_0_3', itemId: 'chr_0013_aglina'),
            $this->row(6, 'Aglina in standard', poolId: 'standard', itemId: 'chr_0013_aglina'),
            $this->row(6, 'Aglina in unknown pool', poolId: 'special_9_9_9', itemId: 'chr_0013_aglina'),
            $this->row(6, 'Aglina without pool', poolId: null, itemId: 'chr_0013_aglina'),
            $this->row(6, 'S without item', poolId: 'special_1_0_3'),
        ];
        $rows[11]['itemId'] = null;

        $result = $this->calculator(self::catalog(self::RATE_UP_POOLS))->compute($rows, $this->bannerGroups()[self::SPECIAL], $this->rarityConfig());

        $this->assertSame(
            [true, false, true, false, true, false, null, null, null, null, null, null],
            array_column($result['items'], 'is_up'),
            'Catalog for a paid or free S, the stored flag first, null for non-S rows and when the catalog cannot tell',
        );
        $sItems = array_values(array_filter($result['items'], static fn (array $item): bool => $item['rarity'] === 6));
        $this->assertSame(array_reverse(array_column($sItems, 'is_up')), array_column($result['summary']['recent_s_pulls'], 'is_up'));
    }

    public function testRateUpFlagOfAJointBannerAcceptsEitherRateUpOperator(): void
    {
        $rows = [
            $this->row(6, 'Laevatain', 'E_CharacterGachaPoolType_Joint', poolId: 'joint_1_2_2', itemId: 'chr_0016_laevat'),
            $this->row(6, 'Aglina', 'E_CharacterGachaPoolType_Joint', poolId: 'joint_1_2_2', itemId: 'chr_0013_aglina'),
            $this->row(6, 'Ember', 'E_CharacterGachaPoolType_Joint', poolId: 'joint_1_2_2', itemId: 'chr_0009_azrila'),
        ];

        $items = $this->calculator(self::catalog(self::RATE_UP_POOLS))->compute($rows, $this->bannerGroups()['E_CharacterGachaPoolType_Joint'], $this->rarityConfig())['items'];

        $this->assertSame([true, true, false], array_column($items, 'is_up'));
    }

    public function testRateUpFlagStaysUnknownWithoutAUsableCatalog(): void
    {
        $rows = [
            $this->row(6, 'Aglina', poolId: 'special_1_0_3', itemId: 'chr_0013_aglina'),
            $this->row(6, 'Ember', poolId: 'special_1_0_3', itemId: 'chr_0009_azrila'),
            $this->row(6, 'Ember stored up', poolId: 'special_1_0_3', itemId: 'chr_0009_azrila', isUp: true),
        ];
        $bannerGroup = $this->bannerGroups()[self::SPECIAL];
        $isUp = fn (?AkeCatalog $catalog): array => array_column($this->calculator($catalog)->compute($rows, $bannerGroup, $this->rarityConfig())['items'], 'is_up');

        $this->assertSame([true, false, true], $isUp(self::catalog(self::RATE_UP_POOLS)), 'Positive control');
        $this->assertSame([null, null, true], $isUp(null), 'No catalog');
        $this->assertSame([null, null, true], $isUp(new AkeCatalog(new ArrayAdapter())), 'Nothing stored');
        $this->assertSame([null, null, true], $isUp(self::catalog(null)), 'Catalog stored before the pools');
    }
}
