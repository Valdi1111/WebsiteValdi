<?php

namespace App\Tests\HoyoverseBundle\Model\Endgame;

use App\HoyoverseBundle\Model\Endgame\ScheduleData;
use App\HoyoverseBundle\Model\Endgame\SpiralAbyssData;
use App\HoyoverseBundle\Model\Endgame\SpiralAbyssFloor;
use App\HoyoverseBundle\Model\Endgame\StygianOnslaughtBest;
use App\HoyoverseBundle\Model\Endgame\StygianOnslaughtData;
use App\HoyoverseBundle\Model\Endgame\StygianOnslaughtRecord;
use App\HoyoverseBundle\Model\Endgame\StygianOnslaughtType;
use App\HoyoverseBundle\Model\Endgame\TheaterLobbyData;
use App\HoyoverseBundle\Model\Endgame\TheaterLobbyType;
use App\HoyoverseBundle\Model\Endgame\TheaterStat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Played / difficulty completed / maximum rules of the Genshin Impact endgame modes.
 */
class GenshinImpactEndgameRulesTest extends TestCase
{
    private static function theaterStat(int $difficulty, int $acts, int $arcana = 0): TheaterStat
    {
        return new TheaterStat()->setDifficultyId($difficulty)->setMaxRoundId($acts)->setTarotFinishedCount($arcana);
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: int, 3: ?int}>
     */
    public static function theaterProvider(): iterable
    {
        // [difficulty_id, max_round_id, tarot_finished_cnt, difficulty completed]
        yield 'not played' => [0, 0, 0, null];
        yield 'Easy, 3 acts' => [1, 3, 0, 1];
        yield 'Easy stopped at act 2' => [1, 2, 0, null];
        yield 'Normal, 6 acts' => [2, 6, 0, 2];
        yield 'Normal stopped at act 4 = Easy' => [2, 4, 0, 1];
        yield 'Hard, 8 acts' => [3, 8, 0, 3];
        yield 'Visionary, 10 acts' => [4, 10, 0, 4];
        yield 'Visionary stopped at act 7 = Normal' => [4, 7, 0, 2];
        yield 'Lunar, 10 acts and both Arcana' => [5, 10, 2, 5];
        yield 'Lunar, 10 acts and one Arcana = Visionary' => [5, 10, 1, 4];
        yield 'Lunar stopped at act 8 = Hard' => [5, 8, 1, 3];
        yield 'unknown difficulty' => [6, 12, 2, null];
    }

    #[DataProvider('theaterProvider')]
    public function testTheaterDifficultyCompleted(int $difficulty, int $acts, int $arcana, ?int $expected): void
    {
        $this->assertSame($expected, self::theaterStat($difficulty, $acts, $arcana)->getCompletedDifficulty());
    }

    public function testTheaterUnknownDifficultyAndStarsAvailable(): void
    {
        $this->assertTrue(self::theaterStat(6, 10)->hasUnknownDifficulty());
        $this->assertFalse(self::theaterStat(5, 10)->hasUnknownDifficulty());
        $this->assertFalse(self::theaterStat(0, 0)->hasUnknownDifficulty());
        $this->assertFalse(new TheaterStat()->hasUnknownDifficulty());

        $this->assertSame(12, self::theaterStat(5, 8)->getMaxMedals());
        $this->assertSame(10, self::theaterStat(4, 10)->getMaxMedals());
        $this->assertSame(3, self::theaterStat(1, 3)->getMaxMedals());
        $this->assertSame(0, self::theaterStat(0, 0)->getMaxMedals());
        $this->assertSame('visionary', self::theaterStat(4, 10)->getDifficulty()['key']);
        $this->assertNull(self::theaterStat(0, 0)->getDifficulty());
    }

    public function testTheaterCurrentPeriodAndPlayed(): void
    {
        $previous = new TheaterLobbyType()->setSchedule(new ScheduleData()->setScheduleType(2))->setHasData(true);
        $current = new TheaterLobbyType()->setSchedule(new ScheduleData()->setScheduleType(1))->setHasData(false);

        $this->assertSame($current, new TheaterLobbyData()->setData([$previous, $current])->getCurrentPeriod());
        $this->assertNull(new TheaterLobbyData()->setData([$previous])->getCurrentPeriod());
        $this->assertNull(new TheaterLobbyData()->getCurrentPeriod());
        $this->assertTrue($previous->isPlayed());
        $this->assertFalse($current->isPlayed());
        $this->assertFalse(new TheaterLobbyType()->isPlayed());
    }

    /**
     * @param list<int>|null $best difficulty and seconds; [] = a record without a best; null = no record
     */
    private static function record(?array $best): StygianOnslaughtRecord
    {
        if ($best === null) {
            return new StygianOnslaughtRecord()->setHasData(false);
        }

        return new StygianOnslaughtRecord()
            ->setHasData(true)
            ->setBest($best !== [] ? new StygianOnslaughtBest()->setDifficulty($best[0])->setSecond($best[1]) : null);
    }

    /**
     * @return iterable<string, array{0: ?array, 1: ?array, 2: bool, 3: ?array, 4: ?int}>
     */
    public static function stygianProvider(): iterable
    {
        // [solo, co-op, played, best (difficulty and seconds), difficulty completed]
        yield 'no record' => [null, null, false, null, null];
        yield 'solo only' => [[4, 197], null, true, [4, 197], 4];
        yield 'co-op only' => [null, [3, 95], true, [3, 95], 3];
        yield 'co-op on a harder difficulty' => [[3, 100], [4, 300], true, [4, 300], 4];
        yield 'same difficulty: the faster run' => [[4, 200], [4, 150], true, [4, 150], 4];
        yield 'record without a best (lost attempt)' => [[], null, true, null, null];
        yield 'Dire' => [[6, 420], [2, 60], true, [6, 420], 6];
    }

    #[DataProvider('stygianProvider')]
    public function testStygianOnslaughtPlayedAndDifficultyCompleted(?array $solo, ?array $coop, bool $played, ?array $best, ?int $completed): void
    {
        $period = new StygianOnslaughtType()->setSingle(self::record($solo))->setMp(self::record($coop));

        $this->assertSame($played, $period->isPlayed());
        $this->assertSame($best, $period->getBest() !== null ? [$period->getBest()->getDifficulty(), $period->getBest()->getSecond()] : null);
        $this->assertSame($completed, $period->getCompletedDifficulty());
        $this->assertFalse($period->hasUnknownDifficulty());
    }

    public function testStygianOnslaughtUnknownDifficulty(): void
    {
        $period = new StygianOnslaughtType()->setSingle(self::record([7, 300]))->setMp(self::record([5, 200]));

        $this->assertTrue($period->hasUnknownDifficulty());
        $this->assertNull($period->getCompletedDifficulty());
        $this->assertFalse(new StygianOnslaughtType()->isPlayed());
        $this->assertNull(new StygianOnslaughtType()->getBest());
    }

    public function testStygianOnslaughtPeriodContainingNow(): void
    {
        $schedule = static fn (string $start, string $end) => new ScheduleData()
            ->setStartTime(new \DateTimeImmutable($start))
            ->setEndTime(new \DateTimeImmutable($end));
        $current = new StygianOnslaughtType()->setSchedule($schedule('2026-02-25 09:00:00 UTC', '2026-03-31 02:59:59 UTC'));
        $previous = new StygianOnslaughtType()->setSchedule($schedule('2026-01-14 09:00:00 UTC', '2026-02-17 02:59:59 UTC'));
        $data = new StygianOnslaughtData()->setData([$current, $previous]);

        $this->assertSame($current, $data->getPeriodAt(new \DateTimeImmutable('2026-03-20 15:00:00 UTC')));
        $this->assertSame($current, $data->getPeriodAt(new \DateTimeImmutable('2026-02-25 09:00:00 UTC')), 'Start included');
        $this->assertNull($data->getPeriodAt(new \DateTimeImmutable('2026-03-31 02:59:59 UTC')), 'End excluded');
        $this->assertSame($previous, $data->getPeriodAt(new \DateTimeImmutable('2026-02-01 12:00:00 UTC')));
        $this->assertNull($data->getPeriodAt(new \DateTimeImmutable('2026-02-20 12:00:00 UTC')), 'Gap between two periods');
        $this->assertNull(new StygianOnslaughtData()->getPeriodAt(new \DateTimeImmutable()));
        $this->assertFalse(new ScheduleData()->setEndTime(new \DateTimeImmutable('2026-03-31 UTC'))->contains(new \DateTimeImmutable('2026-03-20 UTC')), 'Unknown start');
    }

    public function testSpiralAbyssRules(): void
    {
        // Floors 9 and 10 skipped: 18 stars without a battle
        $skipped = new SpiralAbyssData()->setTotalBattleTimes(0)->setTotalStar(18)->setIsUnlock(true);
        $this->assertFalse($skipped->isPlayed());
        $this->assertTrue($skipped->isUnlocked());
        $this->assertSame(18, $skipped->getStars());

        $full = new SpiralAbyssData()->setTotalBattleTimes(6)->setTotalStar(36)
            ->setFloors([new SpiralAbyssFloor()->setIndex(11)->setStar(9)->setMaxStar(9), new SpiralAbyssFloor()->setIndex(12)->setStar(9)->setMaxStar(9)]);
        $this->assertTrue($full->isPlayed());
        $this->assertTrue($full->isFullyCleared());
        $this->assertSame([], $full->getFloorsBelowMaximum());

        $partial = new SpiralAbyssData()->setTotalBattleTimes(9)->setTotalStar(31)
            ->setFloors([
                new SpiralAbyssFloor()->setIndex(11)->setStar(9)->setMaxStar(9),
                new SpiralAbyssFloor()->setIndex(12)->setStar(4)->setMaxStar(9),
            ]);
        $this->assertFalse($partial->isFullyCleared());
        $this->assertSame([12], array_map(static fn (SpiralAbyssFloor $floor) => $floor->getIndex(), $partial->getFloorsBelowMaximum()));

        $this->assertFalse(new SpiralAbyssData()->setIsUnlock(false)->isUnlocked());
        $this->assertTrue(new SpiralAbyssData()->isUnlocked(), 'Missing is_unlock');
        $this->assertFalse(new SpiralAbyssData()->isPlayed());
        // A floor without max_star counts nine stars
        $this->assertFalse(new SpiralAbyssFloor()->setStar(8)->isFullyCleared());
        $this->assertTrue(new SpiralAbyssFloor()->setStar(9)->isFullyCleared());
    }
}
