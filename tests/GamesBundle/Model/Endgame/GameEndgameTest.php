<?php

namespace App\Tests\GamesBundle\Model\Endgame;

use App\GamesBundle\Model\Endgame\EndgameLevel;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameTarget;
use App\GamesBundle\Model\Endgame\EndgameVariant;
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
        $this->assertSame($off, $endgame->needsReminder(new EndgameTarget(EndgameReminder::Off)));
        $this->assertSame($notPlayed, $endgame->needsReminder(new EndgameTarget(EndgameReminder::NotPlayed)));
        $this->assertSame($fullClear, $endgame->needsReminder(new EndgameTarget(EndgameReminder::FullClear)));
    }

    /**
     * A variant with three levels, easiest first.
     */
    private static function variantWithLevels(): EndgameVariant
    {
        return new EndgameVariant('reminder', 'Example Mode', levels: [
            new EndgameLevel('easy', 'Easy'),
            new EndgameLevel('normal', 'Normal'),
            new EndgameLevel('very_hard', 'Very Hard'),
        ]);
    }

    /**
     * @return iterable<string, array{0: ?string, 1: string, 2: bool}>
     */
    public static function levelProvider(): iterable
    {
        // [completed level, chosen level, reminded]
        yield 'completed below the chosen level' => ['easy', 'normal', true];
        yield 'completed the chosen level' => ['normal', 'normal', false];
        yield 'completed a harder level' => ['very_hard', 'normal', false];
        yield 'completed nothing yet' => [null, 'easy', true];
        yield 'completed an unknown level' => ['lunar', 'easy', true];
        yield 'full clear = top level, completed below' => ['normal', 'full_clear', true];
        yield 'full clear = top level, completed' => ['very_hard', 'full_clear', false];
    }

    #[DataProvider('levelProvider')]
    public function testPlayedVariantWithLevelsIsRemindedUntilTheChosenLevel(?string $completed, string $chosen, bool $reminded): void
    {
        $target = self::variantWithLevels()->findTarget($chosen);
        // The maximum does not matter once a level is chosen
        $endgame = self::endgame(true, maxed: !$reminded)->setCompletedLevel($completed);

        $this->assertSame($reminded, $endgame->needsReminder($target));
        $this->assertSame(!$reminded, $endgame->isTargetReached($target));
    }

    public function testVariantWithLevelsNotPlayedLockedOrOff(): void
    {
        $variant = self::variantWithLevels();

        $this->assertTrue(self::endgame(false)->needsReminder($variant->findTarget('easy')));
        $this->assertTrue(self::endgame(false)->needsReminder($variant->findTarget('not_played')));
        $this->assertFalse(self::endgame(true)->setCompletedLevel(null)->needsReminder($variant->findTarget('not_played')));
        $this->assertFalse(self::endgame(false, unlocked: false)->needsReminder($variant->findTarget('easy')));
        $this->assertFalse(self::endgame(false)->needsReminder($variant->findTarget('off')));
    }

    public function testWithoutALevelTheTargetIsTheMaximum(): void
    {
        $fullClear = new EndgameTarget(EndgameReminder::FullClear);

        $this->assertTrue(self::endgame(true, true)->isTargetReached($fullClear));
        $this->assertFalse(self::endgame(true)->setCompletedLevel('very_hard')->isTargetReached($fullClear));
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
        $this->assertNull($endgame->getCompletedLevel());
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
