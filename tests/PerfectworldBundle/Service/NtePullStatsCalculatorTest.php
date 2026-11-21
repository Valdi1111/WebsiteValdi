<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\NtePullStatsCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NtePullStatsCalculatorTest extends TestCase
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

        $calculator = new NtePullStatsCalculator();
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

        $result = (new NtePullStatsCalculator())->compute([$row], self::bannerGroup(NevernessToEvernessService::BANNER_STANDARD), self::labels(NevernessToEvernessService::BANNER_STANDARD));

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

        $calculator = new NtePullStatsCalculator();
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
}
