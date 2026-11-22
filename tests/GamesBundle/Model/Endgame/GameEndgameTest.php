<?php

namespace App\Tests\GamesBundle\Model\Endgame;

use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\GameEndgame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GameEndgameTest extends TestCase
{
    private static function endgame(bool $played, bool $maxed = false, bool $unlocked = true): GameEndgame
    {
        return new GameEndgame()
            ->setName('Example Mode')
            ->setUnlocked($unlocked)
            ->setPlayed($played)
            ->setMaxed($maxed);
    }

    /**
     * @return iterable<string, array{0: GameEndgame, 1: bool, 2: bool, 3: bool}>
     */
    public static function reminderProvider(): iterable
    {
        // [endgame, reminded with off, with not_played, with full_clear]
        yield 'not played' => [self::endgame(false), false, true, true];
        yield 'played below the maximum' => [self::endgame(true), false, false, true];
        yield 'played at the maximum' => [self::endgame(true, true), false, false, false];
        yield 'locked and not played' => [self::endgame(false, unlocked: false), false, false, false];
    }

    #[DataProvider('reminderProvider')]
    public function testNeedsReminderForEverySetting(GameEndgame $endgame, bool $off, bool $notPlayed, bool $fullClear): void
    {
        $this->assertSame($off, $endgame->needsReminder(EndgameReminder::Off));
        $this->assertSame($notPlayed, $endgame->needsReminder(EndgameReminder::NotPlayed));
        $this->assertSame($fullClear, $endgame->needsReminder(EndgameReminder::FullClear));
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
        $endgame = self::endgame(false)->setEndTime(new \DateTimeImmutable('2026-03-20 03:59:59', new \DateTimeZone('+01:00')));

        $this->assertSame($expected, $endgame->endsWithin(new \DateTimeImmutable($now), 7));
    }

    public function testWindowCountsRealHoursAcrossDaylightSavingTime(): void
    {
        // Servers keep UTC+01:00 all year, Europe/Paris is at UTC+02:00 in July
        $endgame = self::endgame(false)->setEndTime(new \DateTimeImmutable('2026-07-10 03:59:59', new \DateTimeZone('+01:00')));

        $this->assertFalse($endgame->endsWithin(new \DateTimeImmutable('2026-07-02 15:00:00', new \DateTimeZone('Europe/Paris')), 7));
        $this->assertTrue($endgame->endsWithin(new \DateTimeImmutable('2026-07-03 15:00:00', new \DateTimeZone('Europe/Paris')), 7));
        $this->assertFalse($endgame->endsWithin(new \DateTimeImmutable('2026-07-03 15:00:00', new \DateTimeZone('Europe/Paris')), 3));
    }

    public function testUnknownEndIsNeverWithinTheWindow(): void
    {
        $this->assertFalse(self::endgame(false)->endsWithin(new \DateTimeImmutable(), 7));
    }

    public function testDefaultsAndProgressLines(): void
    {
        $endgame = new GameEndgame()->addProgressLine('7/9 ★');

        $this->assertSame('reminder', $endgame->getVariant());
        $this->assertTrue($endgame->isUnlocked());
        $this->assertFalse($endgame->isPlayed());
        $this->assertFalse($endgame->isMaxed());
        $this->assertSame(['7/9 ★'], $endgame->getProgressLines());
        $this->assertFalse($endgame->hasProgressIcons());
    }

    public function testProgressLinesWithIcons(): void
    {
        $endgame = new GameEndgame()
            ->addProgressLine('Floor 4: S', '⚔️')
            ->addProgressLine('Floor 5: 2/3 S', '⚔️')
            ->addProgressLine('↳ Stage 2: A (21,000)');

        $this->assertTrue($endgame->hasProgressIcons());
        $this->assertSame(
            ["⚔️\u{00A0}\u{00A0}Floor 4: S", "⚔️\u{00A0}\u{00A0}Floor 5: 2/3 S", '↳ Stage 2: A (21,000)'],
            $endgame->getProgressLines()
        );
    }
}
