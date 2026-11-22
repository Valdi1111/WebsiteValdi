<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullStatsCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PerfectworldPullStatsCalculatorTest extends TestCase
{
    private int $nextId = 1;
    private int $minute = 0;

    private function row(string $kind, string $rewardType, ?int $rarity, string $name = 'Item', int $roll = 3, int $quantity = 1, string $month = '2026-06'): array
    {
        $this->minute++;

        return [
            'id' => $this->nextId++,
            'bannerKey' => 'x',
            'poolId' => null,
            'pulledAt' => new \DateTimeImmutable(sprintf('%s-01 10:%02d:00', $month, $this->minute % 60), new \DateTimeZone('UTC')),
            'groupPosition' => 0,
            'kind' => $kind,
            'rollResult' => $kind === PerfectworldPull::KIND_ROLL ? $roll : null,
            'rewardType' => $rewardType,
            'rewardId' => strtolower($name),
            'name' => $name,
            'rarity' => $rarity,
            'quantity' => $quantity,
        ];
    }

    private function roll(string $rewardType = 'arc', ?int $rarity = NevernessToEvernessService::RARITY_B, string $name = 'Be Happy', string $month = '2026-06'): array
    {
        return $this->row(PerfectworldPull::KIND_ROLL, $rewardType, $rarity, $name, month: $month);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bannerGroup(string $key): array
    {
        $game = new NevernessToEvernessService(new NullLogger());

        return $game->getGachaConfig()->getBannerGroups('categories')[$key];
    }

    private static function labels(string $key): array
    {
        return (new NevernessToEvernessService(new NullLogger()))->getBannerPullLabels($key);
    }

    public function testBoardCountsOnlyRollsAndResetsOnSCharactersOnly(): void
    {
        $rows = [];
        for ($i = 0; $i < 9; $i++) {
            $rows[] = $this->roll();
        }
        $rows[] = $this->roll('item', NevernessToEvernessService::RARITY_S, 'Solid Dice'); // roll 10: S item, no reset
        $rows[] = $this->row(PerfectworldPull::KIND_POINTS_GIFT, 'character', NevernessToEvernessService::RARITY_A, 'Adler');
        $rows[] = $this->row(PerfectworldPull::KIND_SLUMBERLAND, 'item', NevernessToEvernessService::RARITY_S, 'Warp Piece', quantity: 30);
        $rows[] = $this->roll();
        $rows[] = $this->roll('character', NevernessToEvernessService::RARITY_S, 'Blackbird'); // roll 12
        $rows[] = $this->roll('arc', NevernessToEvernessService::RARITY_A, 'Umbrella', '2026-07'); // roll 13

        $calculator = new PerfectworldPullStatsCalculator(new PullGapPlanner());
        $result = $calculator->compute($rows, self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED), self::labels(NevernessToEvernessService::BANNER_LIMITED));
        $summary = $result['summary'];

        $this->assertSame(13, $summary['total_pulls']);
        $this->assertSame(1, $summary['s_count']);
        $this->assertSame(2, $summary['a_count'], 'A character from the gift + A arc from a roll');
        $this->assertSame(1, $summary['s_pity'], 'One roll after the S character');
        $this->assertSame(3, $summary['a_pity'], 'Three rolls since the Points Gift');
        $this->assertSame(12.0, (float) $summary['avg_s_pity']);
        $this->assertSame(6.5, (float) $summary['avg_a_pity'], '13 rolls / 2 A');
        $this->assertSame('Rolls', $summary['pull_label']);
        $this->assertSame('Roll', $summary['pull_singular_label']);
        $this->assertCount(1, $summary['recent_s_pulls']);
        $this->assertSame('Blackbird', $summary['recent_s_pulls'][0]['name']);
        $this->assertSame(12, $summary['recent_s_pulls'][0]['pity']);

        $items = $result['items'];
        $this->assertNull($items[9]['pity'], 'S items do not carry pity');
        $this->assertSame(10, $items[9]['pull_number']);
        $this->assertSame('Points Gift', $items[10]['roll_label']);
        $this->assertNull($items[10]['pull_number']);
        $this->assertSame('Slumberland', $items[11]['roll_label']);
        $this->assertSame('Warp Piece ×30', $items[11]['name']);
        $this->assertSame('3', $items[0]['roll_label']);
        $this->assertSame('be happy', $items[0]['item_id']);
        $this->assertSame('arc', $items[0]['item_type']);
        $this->assertSame('Arc', $items[0]['item_type_label']);

        $this->assertSame(['month' => '2026-06', 'total' => 12, 's_count' => 1, 'a_count' => 1], $result['monthly']['2026-06']);
        $this->assertSame(['month' => '2026-07', 'total' => 1, 's_count' => 0, 'a_count' => 1], $result['monthly']['2026-07']);
    }

    public function testRelabelledRollWithoutDiceValueShowsQuestionMark(): void
    {
        $row = $this->roll();
        $row['rollResult'] = null;

        $result = (new PerfectworldPullStatsCalculator(new PullGapPlanner()))->compute([$row], self::bannerGroup(NevernessToEvernessService::BANNER_STANDARD), self::labels(NevernessToEvernessService::BANNER_STANDARD));

        $this->assertSame('?', $result['items'][0]['roll_label']);
    }

    public function testArcResetsSOnAnySArcAndAOnAOrS(): void
    {
        $rows = [];
        for ($i = 0; $i < 9; $i++) {
            $rows[] = $this->row(PerfectworldPull::KIND_DRAW, 'arc', NevernessToEvernessService::RARITY_B);
        }
        $rows[] = $this->row(PerfectworldPull::KIND_DRAW, 'arc', NevernessToEvernessService::RARITY_A, 'Umbrella'); // 10
        for ($i = 0; $i < 49; $i++) {
            $rows[] = $this->row(PerfectworldPull::KIND_DRAW, 'arc', $i % 10 === 9 ? NevernessToEvernessService::RARITY_A : NevernessToEvernessService::RARITY_B);
        }
        $rows[] = $this->row(PerfectworldPull::KIND_DRAW, 'arc', NevernessToEvernessService::RARITY_S, 'Crime and Punishment'); // 60
        $rows[] = $this->row(PerfectworldPull::KIND_DRAW, 'arc', null, 'Unknown #fork_new'); // 61, unknown rarity

        $calculator = new PerfectworldPullStatsCalculator(new PullGapPlanner());
        $result = $calculator->compute($rows, self::bannerGroup(NevernessToEvernessService::BANNER_ARC), self::labels(NevernessToEvernessService::BANNER_ARC));
        $summary = $result['summary'];

        $this->assertSame(61, $summary['total_pulls']);
        $this->assertSame(1, $summary['s_count']);
        $this->assertSame(60, $summary['recent_s_pulls'][0]['pity']);
        $this->assertSame(1, $summary['s_pity']);
        $this->assertSame(1, $summary['a_pity'], 'The S Arc also reset the A pity');
        $this->assertSame(10, $result['items'][9]['pity']);
        $this->assertSame('Arcs', $summary['pull_label']);
        $this->assertArrayNotHasKey('roll_label', $result['items'][0]);

        // Unknown rarity is listed with the B filter, newest first
        $page = $calculator->filterAndPaginate($result['items'], [NevernessToEvernessService::RARITY_B], null, 1, 5);
        $this->assertSame('Unknown #fork_new', $page['items'][0]['name']);
        $this->assertSame(55, $page['total'], '54 B Arcs + 1 unknown');

        $search = $calculator->filterAndPaginate($result['items'], [], 'crime', 1, 5);
        $this->assertSame(1, $search['total']);
    }

    private const array SUMMARY_KEYS = [
        'key', 'name', 'categories', 'pull_label', 'pull_singular_label', 'total_pulls', 's_count', 'a_count', 'b_count',
        's_pity', 's_max_pity', 'a_pity', 'a_max_pity', 'avg_s_pity', 'avg_a_pity', 'recent_s_pulls',
    ];

    private const array ITEM_KEYS = [
        'id', 'pull_number', 'kind', 'pool_id', 'gacha_name', 'pool_name', 'item_id', 'item_type', 'item_type_label',
        'name', 'quantity', 'rarity', 'pity', 'pulled_at',
    ];

    private const array WINDOW_KEYS = ['recorded_pity', 'insert_after', 'insert_before', 'suggested_at', 'has_room'];

    private static function calculator(): PerfectworldPullStatsCalculator
    {
        return new PerfectworldPullStatsCalculator(new PullGapPlanner());
    }

    /**
     * Row in the shape of PerfectworldPullRepository::findBannerRows() (origin hydrated as an enum).
     *
     * @return array<string, mixed>
     */
    private static function dbRow(
        int $id,
        string $pulledAt,
        string $kind,
        string $rewardType,
        ?int $rarity,
        string $name = 'Be Happy',
        PullOrigin $origin = PullOrigin::Recorded,
        ?int $declaredPity = null,
        int $position = 0,
    ): array {
        return [
            'id' => $id,
            'bannerKey' => 'x',
            'poolId' => null,
            'pulledAt' => WallClockDateTimeImmutableType::fromString($pulledAt),
            'groupPosition' => $position,
            'kind' => $kind,
            'rollResult' => $kind === PerfectworldPull::KIND_ROLL && $origin === PullOrigin::Recorded ? 3 : null,
            'rewardType' => $rewardType,
            'rewardId' => str_replace(' ', '_', strtolower($name)),
            'name' => $name,
            'rarity' => $rarity,
            'quantity' => 1,
            'origin' => $origin,
            'declaredPity' => $declaredPity,
        ];
    }

    private static function boardRoll(int $id, string $pulledAt, string $rewardType = 'arc', ?int $rarity = NevernessToEvernessService::RARITY_B, string $name = 'Be Happy', ?int $declaredPity = null): array
    {
        return self::dbRow($id, $pulledAt, PerfectworldPull::KIND_ROLL, $rewardType, $rarity, $name, declaredPity: $declaredPity);
    }

    private static function arc(int $id, string $pulledAt, int $rarity, ?int $declaredPity = null): array
    {
        return self::dbRow($id, $pulledAt, PerfectworldPull::KIND_DRAW, 'arc', $rarity, 'Arc ' . $rarity, declaredPity: $declaredPity);
    }

    /**
     * Plans the banner, then applies the plan to the rows the way ManualPullService::realign() does
     * (fresh negative positions) and sorts them like the repository.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function realign(array $rows, string $bannerKey): array
    {
        $calculator = self::calculator();
        $plan = (new PullGapPlanner())->plan($calculator->toTimelineEntries($rows, self::bannerGroup($bannerKey)));

        $rows = self::applyPlan($rows, $plan, $bannerKey);
        self::assertTrue(
            (new PullGapPlanner())->plan($calculator->toTimelineEntries($rows, self::bannerGroup($bannerKey)))->isEmpty(),
            'A second plan on the result is empty',
        );

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function applyPlan(array $rows, FillerPlan $plan, string $bannerKey): array
    {
        $positions = [];
        foreach ($rows as $row) {
            $second = $row['pulledAt']->format('Y-m-d H:i:s');
            $positions[$second] = min($positions[$second] ?? 0, $row['groupPosition']);
        }
        $nextId = max(array_column($rows, 'id')) + 1;

        $result = [];
        foreach ($rows as $row) {
            if (isset($plan->merges[$row['id']]) || in_array($row['id'], $plan->fillerDeletes, true)) {
                continue;
            }
            $row['declaredPity'] = $plan->declaredPityUpdates[$row['id']] ?? $row['declaredPity'];
            if (isset($plan->fillerMoves[$row['id']])) {
                $row['pulledAt'] = $plan->fillerMoves[$row['id']];
                $row['groupPosition'] = ManualPullService::allocatePosition($positions, $row['pulledAt']);
            }
            $result[] = $row;
        }

        $kind = NevernessToEvernessService::isBoardBanner($bannerKey) ? PerfectworldPull::KIND_ROLL : PerfectworldPull::KIND_DRAW;
        foreach ($plan->fillerCreates as $slot) {
            $result[] = [
                'id' => $nextId++,
                'bannerKey' => $bannerKey,
                'poolId' => null,
                'pulledAt' => $slot->pulledAt,
                'groupPosition' => ManualPullService::allocatePosition($positions, $slot->pulledAt),
                'kind' => $kind,
                'rollResult' => null,
                'rewardType' => ManualPullService::FILLER_REWARD_TYPE,
                'rewardId' => ManualPullService::FILLER_REWARD_ID,
                'name' => 'Unknown',
                'rarity' => 0,
                'quantity' => 1,
                'origin' => PullOrigin::Filler,
                'declaredPity' => null,
            ];
        }

        usort($result, static fn (array $a, array $b): int => [$a['pulledAt']->getTimestamp(), -$a['groupPosition']] <=> [$b['pulledAt']->getTimestamp(), -$b['groupPosition']]);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function itemById(array $items, int $id): array
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        self::fail(sprintf('Item #%d not found.', $id));
    }

    public function testPredicatesFollowTheBannerRules(): void
    {
        $calculator = self::calculator();
        $board = self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED);
        $arc = self::bannerGroup(NevernessToEvernessService::BANNER_ARC);

        $sCharacter = self::boardRoll(1, '2026-06-01 10:00:00', 'character', NevernessToEvernessService::RARITY_S, 'Blackbird');
        $sItem = self::boardRoll(2, '2026-06-01 10:00:00', 'item', NevernessToEvernessService::RARITY_S, 'Solid Dice');
        $giftS = self::dbRow(3, '2026-06-01 10:00:00', PerfectworldPull::KIND_POINTS_GIFT, 'character', NevernessToEvernessService::RARITY_S, 'Blackbird');
        $chase = self::dbRow(4, '2026-06-01 10:00:00', PerfectworldPull::KIND_SLUMBERLAND, 'item', NevernessToEvernessService::RARITY_S, 'Warp Piece');
        $boardFiller = self::dbRow(5, '2026-06-01 10:00:00', PerfectworldPull::KIND_ROLL, 'unknown', 0, 'Unknown', PullOrigin::Filler);

        $this->assertTrue($calculator->countsAsPull($sCharacter, $board));
        $this->assertTrue($calculator->isS($sCharacter, $board));
        $this->assertTrue($calculator->countsAsPull($sItem, $board));
        $this->assertFalse($calculator->isS($sItem, $board), 'S items never reset the board pity');
        $this->assertFalse($calculator->countsAsPull($giftS, $board));
        $this->assertFalse($calculator->isS($giftS, $board), 'A Points Gift is not a roll');
        $this->assertFalse($calculator->countsAsPull($chase, $board));
        $this->assertTrue($calculator->countsAsPull($boardFiller, $board), 'Board fillers are rolls');
        $this->assertFalse($calculator->isS($boardFiller, $board));

        $sArc = self::arc(6, '2026-06-01 10:00:00', NevernessToEvernessService::RARITY_S);
        $aArc = self::arc(7, '2026-06-01 10:00:00', NevernessToEvernessService::RARITY_A);
        $arcFiller = self::dbRow(8, '2026-06-01 10:00:00', PerfectworldPull::KIND_DRAW, 'unknown', 0, 'Unknown', PullOrigin::Filler);

        $this->assertTrue($calculator->isS($sArc, $arc));
        $this->assertTrue($calculator->countsAsPull($aArc, $arc));
        $this->assertFalse($calculator->isS($aArc, $arc));
        $this->assertTrue($calculator->countsAsPull($arcFiller, $arc));
        $this->assertFalse($calculator->isS($arcFiller, $arc));
    }

    public function testTimelineEntriesUseTheSamePredicates(): void
    {
        $rows = [
            self::boardRoll(1, '2026-06-01 10:00:00'),
            self::dbRow(2, '2026-06-01 10:00:01', PerfectworldPull::KIND_POINTS_GIFT, 'character', NevernessToEvernessService::RARITY_A, 'Adler'),
            self::dbRow(3, '2026-06-01 10:00:02', PerfectworldPull::KIND_ROLL, 'unknown', 0, 'Unknown', PullOrigin::Filler, position: -1),
            self::dbRow(4, '2026-06-01 10:00:03', PerfectworldPull::KIND_ROLL, 'character', NevernessToEvernessService::RARITY_S, 'Black Bird', PullOrigin::Manual, 4, -1),
        ];
        $rows[0]['origin'] = 'recorded';
        unset($rows[1]['origin'], $rows[1]['declaredPity']);
        $rows[3]['rewardId'] = 'Fashion_Glide_1075';

        $entries = self::calculator()->toTimelineEntries($rows, self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED));

        $this->assertSame([1, 2, 3, 4], array_map(static fn ($entry): int => $entry->id, $entries));
        $this->assertSame([PullOrigin::Recorded, PullOrigin::Recorded, PullOrigin::Filler, PullOrigin::Manual], array_map(static fn ($entry): PullOrigin => $entry->origin, $entries));
        $this->assertSame([true, false, true, true], array_map(static fn ($entry): bool => $entry->countsAsPull, $entries));
        $this->assertSame([false, false, false, true], array_map(static fn ($entry): bool => $entry->isS, $entries));
        $this->assertSame(4, $entries[3]->declaredPity);
        $this->assertSame('fashion_glide_1075', $entries[3]->itemId, 'Item ids are compared case-insensitively');
        $this->assertSame('Black Bird', $entries[3]->name);
        $this->assertSame('2026-06-01 10:00:03', $entries[3]->pulledAt->format('Y-m-d H:i:s'));
    }

    public function testAnOfficialSCountsLikeAManualS(): void
    {
        $rows = [
            self::boardRoll(1, '2026-06-01 10:00:00'),
            self::boardRoll(2, '2026-06-01 10:05:00', 'character', NevernessToEvernessService::RARITY_S, 'Blackbird', 2),
        ];
        $rows[1]['origin'] = 'official';

        $result = self::calculator()->compute(
            $rows,
            self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED),
            self::labels(NevernessToEvernessService::BANNER_LIMITED),
            now: WallClockDateTimeImmutableType::fromString('2026-07-01 00:00:00'),
        );

        $this->assertSame('official', $result['items'][1]['origin'], 'Not read back as a recorded row');
        $this->assertSame(2, $result['items'][1]['pity']);
        $this->assertSame(1, $result['summary']['s_count']);
        $this->assertSame(PullOrigin::Official, self::calculator()->toTimelineEntries($rows, self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED))[1]->origin);
    }

    public function testRateUpFlagIsExposedWhereTheRowHasOne(): void
    {
        $rows = [
            self::boardRoll(1, '2026-06-01 10:00:00'),
            self::boardRoll(2, '2026-06-01 10:05:00', 'character', NevernessToEvernessService::RARITY_S, 'Blackbird'),
        ];
        $rows[1]['isUp'] = true;

        $result = self::calculator()->compute(
            $rows,
            self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED),
            self::labels(NevernessToEvernessService::BANNER_LIMITED),
            now: WallClockDateTimeImmutableType::fromString('2026-07-01 00:00:00'),
        );

        $this->assertSame([null, true], array_column($result['items'], 'is_up'));
    }

    public function testRowsWithoutFillersKeepTheExistingKeysAndAddWindowFields(): void
    {
        $rows = [
            self::boardRoll(1, '2026-06-01 10:00:00'),
            self::boardRoll(2, '2026-06-01 10:05:00', 'character', NevernessToEvernessService::RARITY_S, 'Blackbird'),
            self::boardRoll(3, '2026-06-01 11:00:00'),
        ];

        $result = self::calculator()->compute(
            $rows,
            self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED),
            self::labels(NevernessToEvernessService::BANNER_LIMITED),
            now: WallClockDateTimeImmutableType::fromString('2026-07-01 00:00:00'),
        );
        $summary = $result['summary'];

        $this->assertSame([...self::SUMMARY_KEYS, 'unknown_count', 'open_window', 'oldest_s'], array_keys($summary));
        $this->assertSame([...self::ITEM_KEYS, 'roll_label', 'origin', 'declared_pity', 'is_up'], array_keys($result['items'][0]));
        $this->assertSame([...self::ITEM_KEYS, 'roll_label', 'origin', 'declared_pity', 'is_up', ...self::WINDOW_KEYS], array_keys($result['items'][1]));
        $this->assertSame(0, $summary['unknown_count']);
        $this->assertSame(2, $summary['b_count']);
        $this->assertSame('recorded', $result['items'][0]['origin']);
        $this->assertNull($result['items'][0]['declared_pity']);

        $s = $result['items'][1];
        $this->assertSame(2, $s['pity']);
        $this->assertSame(2, $s['recorded_pity']);
        $this->assertNull($s['insert_after'], 'No S before: start of the history');
        $this->assertSame('2026-06-01 10:00:00', $s['insert_before']);
        $this->assertSame('2026-06-01 09:59:00', $s['suggested_at']);
        $this->assertTrue($s['has_room']);
        $this->assertSame($s, $summary['recent_s_pulls'][0], 'recent_s_pulls carries the same window fields');
        $this->assertSame($s, $summary['oldest_s']);

        $this->assertSame([
            'recorded_pity' => 1,
            'declared_pity' => null,
            'insert_after' => '2026-06-01 10:05:00',
            'insert_before' => '2026-06-01 11:00:00',
            'suggested_at' => '2026-06-01 10:59:00',
            'has_room' => true,
        ], $summary['open_window']);
    }

    public function testOldestSIsNotCappedLikeTheRecentList(): void
    {
        $rows = [];
        for ($i = 1; $i <= 52; $i++) {
            $rows[] = self::boardRoll($i, sprintf('2026-06-01 10:%02d:00', $i), 'character', NevernessToEvernessService::RARITY_S, 'Blackbird');
        }

        $summary = self::calculator()->compute($rows, self::bannerGroup(NevernessToEvernessService::BANNER_STANDARD), self::labels(NevernessToEvernessService::BANNER_STANDARD))['summary'];

        $this->assertCount(50, $summary['recent_s_pulls']);
        $this->assertSame(52, $summary['recent_s_pulls'][0]['id']);
        $this->assertSame(3, $summary['recent_s_pulls'][49]['id']);
        $this->assertSame(1, $summary['oldest_s']['id']);
        $this->assertArrayHasKey('has_room', $summary['oldest_s']);
        $this->assertNull(self::calculator()->compute([], self::bannerGroup(NevernessToEvernessService::BANNER_STANDARD), self::labels(NevernessToEvernessService::BANNER_STANDARD))['summary']['oldest_s']);
    }

    public function testBoardFillersAreUnknownRollsThatRestartTheAPity(): void
    {
        $rows = self::realign([
            self::boardRoll(1, '2026-06-01 09:00:00', 'character', NevernessToEvernessService::RARITY_S, 'Blackbird'),
            self::dbRow(2, '2026-06-01 09:00:30', PerfectworldPull::KIND_POINTS_GIFT, 'character', NevernessToEvernessService::RARITY_A, 'Adler'),
            self::boardRoll(3, '2026-06-01 12:00:00'),
            self::boardRoll(4, '2026-06-01 12:00:30', 'arc', NevernessToEvernessService::RARITY_A, 'Umbrella'),
            self::boardRoll(5, '2026-06-01 12:01:00', 'character', NevernessToEvernessService::RARITY_S, 'Nanally', 7),
        ], NevernessToEvernessService::BANNER_LIMITED);

        // 7 declared - 3 recorded rolls: 4 fillers in the widest gap, after the Points Gift
        $fillers = array_values(array_filter($rows, static fn (array $row): bool => $row['origin'] === PullOrigin::Filler));
        $this->assertCount(4, $fillers);
        foreach ($fillers as $filler) {
            $this->assertGreaterThan('2026-06-01 09:00:30', $filler['pulledAt']->format('Y-m-d H:i:s'));
            $this->assertLessThan('2026-06-01 12:00:00', $filler['pulledAt']->format('Y-m-d H:i:s'));
            $this->assertSame(-1, $filler['groupPosition'], 'One filler per second here, each at -1');
        }

        $result = self::calculator()->compute($rows, self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED), self::labels(NevernessToEvernessService::BANNER_LIMITED));
        $summary = $result['summary'];
        $items = $result['items'];

        $this->assertSame([1, null, 2, 3, 4, 5, 6, 7, 8], array_column($items, 'pull_number'), 'Fillers are numbered like rolls');
        $s = self::itemById($items, 5);
        $this->assertSame(7, $s['pity'], 'The S shows the declared pity');
        $this->assertSame(7, $s['declared_pity']);
        $this->assertSame(3, $s['recorded_pity']);

        foreach (array_slice($items, 2, 4) as $filler) {
            $this->assertSame('filler', $filler['origin']);
            $this->assertNull($filler['pity']);
            $this->assertSame(0, $filler['rarity']);
            $this->assertSame('Unknown', $filler['name']);
            $this->assertSame('Unknown', $filler['item_type_label']);
            $this->assertSame('?', $filler['roll_label']);
            $this->assertArrayNotHasKey('has_room', $filler);
        }

        $this->assertSame(8, $summary['total_pulls']);
        $this->assertSame(4, $summary['unknown_count']);
        $this->assertSame(1, $summary['b_count'], 'Fillers are never B');
        $this->assertSame(2, $summary['a_count']);
        $this->assertSame(3, $summary['a_pity'], 'The A pity restarted after the fillers (7 without the reset)');
        $this->assertSame(2.0, (float) $summary['avg_a_pity'], '(8 rolls - 4 unknown) / 2 A');
        $this->assertSame(4.0, (float) $summary['avg_s_pity']);
        $this->assertSame(8, $result['monthly']['2026-06']['total']);

        // Only rarity 0 lists the unknown pulls
        $page = self::calculator()->filterAndPaginate($items, [0], null, 1, 10);
        $this->assertSame(4, $page['total']);
    }

    public function testManualSOnABoardShowsItsDeclaredPity(): void
    {
        $rows = self::realign([
            self::boardRoll(1, '2026-06-01 09:00:00', 'character', NevernessToEvernessService::RARITY_S, 'Blackbird'),
            self::dbRow(10, '2026-06-01 10:00:00', PerfectworldPull::KIND_ROLL, 'character', NevernessToEvernessService::RARITY_S, 'Nanally', PullOrigin::Manual, 5, -1),
            self::boardRoll(2, '2026-06-01 11:00:00'),
            self::boardRoll(3, '2026-06-01 11:00:30', 'character', NevernessToEvernessService::RARITY_S, 'Blackbird'),
        ], NevernessToEvernessService::BANNER_LIMITED);

        $result = self::calculator()->compute($rows, self::bannerGroup(NevernessToEvernessService::BANNER_LIMITED), self::labels(NevernessToEvernessService::BANNER_LIMITED));

        $manual = self::itemById($result['items'], 10);
        $this->assertSame('manual', $manual['origin']);
        $this->assertSame(5, $manual['pity']);
        $this->assertSame(5, $manual['declared_pity']);
        $this->assertSame(1, $manual['recorded_pity']);
        $this->assertSame('2026-06-01 09:00:00', $manual['insert_after']);
        $this->assertSame(2, self::itemById($result['items'], 3)['pity'], 'The next S counts from the manual one');
        $this->assertSame(4, $result['summary']['unknown_count']);
        $this->assertSame(3, $result['summary']['s_count']);
    }

    public function testArcFillersCountAsArcsAndRestartTheAPity(): void
    {
        $rows = self::realign([
            self::arc(1, '2026-06-01 09:00:00', NevernessToEvernessService::RARITY_S),
            self::arc(2, '2026-06-01 09:30:00', NevernessToEvernessService::RARITY_A),
            self::arc(3, '2026-06-01 12:00:00', NevernessToEvernessService::RARITY_B),
            self::arc(4, '2026-06-01 12:01:00', NevernessToEvernessService::RARITY_A),
            self::arc(5, '2026-06-01 12:02:00', NevernessToEvernessService::RARITY_S, 20),
            // A manual S Arc in the open window
            self::dbRow(9, '2026-06-02 08:00:00', PerfectworldPull::KIND_DRAW, 'arc', NevernessToEvernessService::RARITY_S, 'Crime and Punishment', PullOrigin::Manual, 3, -1),
        ], NevernessToEvernessService::BANNER_ARC);

        $result = self::calculator()->compute($rows, self::bannerGroup(NevernessToEvernessService::BANNER_ARC), self::labels(NevernessToEvernessService::BANNER_ARC));
        $summary = $result['summary'];

        // 20 - 4 recorded = 16 fillers after the first A, 3 - 1 = 2 before the manual S
        $this->assertSame(18, $summary['unknown_count']);
        $this->assertSame(1 + 4 + 18 + 1, $summary['total_pulls']);
        $this->assertSame(20, self::itemById($result['items'], 5)['pity']);
        $this->assertSame(3, self::itemById($result['items'], 9)['pity']);
        $this->assertSame(2, self::itemById($result['items'], 4)['pity'], 'A pity restarted after the fillers');
        $this->assertSame(1, $summary['b_count']);
        $this->assertSame(1.5, (float) $summary['avg_a_pity'], 'Arc: (1 + 2) / 2');

        $filler = $result['items'][2];
        $this->assertSame('filler', $filler['origin']);
        $this->assertSame(PerfectworldPull::KIND_DRAW, $filler['kind']);
        $this->assertSame(3, $filler['pull_number']);
        $this->assertNull($filler['pity']);
        $this->assertArrayNotHasKey('roll_label', $filler);
    }
}
