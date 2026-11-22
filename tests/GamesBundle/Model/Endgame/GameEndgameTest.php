<?php

namespace App\Tests\GamesBundle\Model\Endgame;

use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Endgame\GameEndgameStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GameEndgameTest extends TestCase
{
    private function endgame(bool $played, ?int $progress, int $maxProgress = 9, bool $unlocked = true): GameEndgame
    {
        return new GameEndgame()
            ->setName('Example Mode')
            ->setUnlocked($unlocked)
            ->setPlayed($played)
            ->setProgress($progress)
            ->setMaxProgress($maxProgress)
            ->setProgressUnit('★');
    }

    /**
     * @return iterable<string, array{0: GameEndgame, 1: bool, 2: bool}>
     */
    public static function reminderProvider(): iterable
    {
        $endgame = static fn (bool $played, ?int $progress, bool $unlocked = true) => new GameEndgame()
            ->setUnlocked($unlocked)
            ->setPlayed($played)
            ->setProgress($progress)
            ->setMaxProgress(9);

        // [endgame, reminded when only unplayed modes count, reminded until full clear]
        yield 'not played' => [$endgame(false, 0), true, true];
        yield 'played, 8 of 9' => [$endgame(true, 8), false, true];
        yield 'played, 9 of 9' => [$endgame(true, 9), false, false];
        yield 'played, progress unknown' => [$endgame(true, null), false, true];
        yield 'locked and not played' => [$endgame(false, 0, false), false, false];
    }

    #[DataProvider('reminderProvider')]
    public function testNeedsReminderInBothModes(GameEndgame $endgame, bool $untilPlayed, bool $untilMaxed): void
    {
        $this->assertSame($untilPlayed, $endgame->needsReminder(false));
        $this->assertSame($untilMaxed, $endgame->needsReminder(true));
    }

    public function testIsMaxedNeedsBothValues(): void
    {
        $this->assertTrue($this->endgame(true, 9)->isMaxed());
        $this->assertTrue($this->endgame(true, 10)->isMaxed());
        $this->assertFalse($this->endgame(true, 8)->isMaxed());
        $this->assertFalse($this->endgame(true, null)->isMaxed());
        $this->assertFalse($this->endgame(true, 9)->setMaxProgress(null)->isMaxed());
    }

    /**
     * Period ending 2026-03-20 03:59:59 at UTC+01:00; the reminder task runs at 15:00 Europe/Paris.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function windowProvider(): iterable
    {
        yield '8 days before' => ['2026-03-12 15:00:00 Europe/Paris', false];
        yield '7 days before' => ['2026-03-13 15:00:00 Europe/Paris', true];
        yield 'last day' => ['2026-03-19 15:00:00 Europe/Paris', true];
        yield 'last minute' => ['2026-03-20 03:59:00 +01:00', true];
        yield 'at the end' => ['2026-03-20 03:59:59 +01:00', false];
        yield 'after the end' => ['2026-03-20 15:00:00 Europe/Paris', false];
        yield 'exactly 7 x 24 h before' => ['2026-03-13 03:59:59 +01:00', true];
        yield '7 x 24 h and 1 s before' => ['2026-03-13 03:59:58 +01:00', false];
    }

    #[DataProvider('windowProvider')]
    public function testEndsWithinSevenDays(string $now, bool $expected): void
    {
        $endgame = $this->endgame(false, 0)->setEndTime(new \DateTimeImmutable('2026-03-20 03:59:59', new \DateTimeZone('+01:00')));

        $this->assertSame($expected, $endgame->endsWithin(new \DateTimeImmutable($now), 7));
    }

    public function testWindowCountsRealHoursAcrossDaylightSavingTime(): void
    {
        // Servers keep UTC+01:00 all year, Europe/Paris is at UTC+02:00 in July
        $endgame = $this->endgame(false, 0)->setEndTime(new \DateTimeImmutable('2026-07-10 03:59:59', new \DateTimeZone('+01:00')));

        $this->assertFalse($endgame->endsWithin(new \DateTimeImmutable('2026-07-02 15:00:00', new \DateTimeZone('Europe/Paris')), 7));
        $this->assertTrue($endgame->endsWithin(new \DateTimeImmutable('2026-07-03 15:00:00', new \DateTimeZone('Europe/Paris')), 7));
        $this->assertFalse($endgame->endsWithin(new \DateTimeImmutable('2026-07-03 15:00:00', new \DateTimeZone('Europe/Paris')), 3));
    }

    public function testUnknownEndIsNeverWithinTheWindow(): void
    {
        $this->assertFalse($this->endgame(false, 0)->endsWithin(new \DateTimeImmutable(), 7));
    }

    public function testFormattedProgressAndStages(): void
    {
        $endgame = $this->endgame(true, 7)
            ->addStage(new GameEndgameStage()->setName('Example Boss')->setScore(18450)->setMaxScore(65000)->setResult('1★'))
            ->addStage(new GameEndgameStage()->setName('Stage 2')->setScore(0)->setMaxScore(50000))
            ->addStage(new GameEndgameStage()->setName('Stage 3')->setScore(31000));

        $this->assertSame('7/9 ★', $endgame->getFormattedProgress());
        $this->assertSame(
            ['Example Boss: 18,450/65,000 (1★)', 'Stage 2: not played', 'Stage 3: 31,000'],
            array_map(static fn (GameEndgameStage $stage) => $stage->getFormattedOutput(), $endgame->getStages())
        );
        $this->assertFalse($endgame->getStages()[1]->isPlayed());
    }
}
