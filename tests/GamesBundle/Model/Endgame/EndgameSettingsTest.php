<?php

namespace App\Tests\GamesBundle\Model\Endgame;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameLevel;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameSettings;
use App\GamesBundle\Model\Endgame\EndgameTarget;
use App\GamesBundle\Model\Endgame\EndgameVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Endgame declarations and the per-profile settings resolved against them.
 */
class EndgameSettingsTest extends TestCase
{
    /**
     * @return list<EndgameDefinition>
     */
    private static function definitions(): array
    {
        return [
            new EndgameDefinition('first_mode', 'First Mode'),
            new EndgameDefinition('second_mode', 'Second Mode', [
                new EndgameVariant('reminder', 'Second Mode'),
                new EndgameVariant('hard_reminder', 'Second Mode (Hard)', EndgameReminder::Off),
            ], defaultDaysBeforeEnd: 3),
        ];
    }

    public function testAModeWithoutVariantsHasASingleOneNamedLikeIt(): void
    {
        $definition = new EndgameDefinition('first_mode', 'First Mode');

        $this->assertCount(1, $definition->variants);
        $this->assertSame('reminder', $definition->variants[0]->key);
        $this->assertSame('First Mode', $definition->variants[0]->name);
        $this->assertSame(EndgameReminder::NotPlayed, $definition->variants[0]->defaultReminder);
        $this->assertSame(7, $definition->defaultDaysBeforeEnd);
        $this->assertSame($definition->variants[0], $definition->getVariant('reminder'));
        $this->assertNull($definition->getVariant('hard_reminder'));
    }

    /**
     * @return iterable<string, array{0: callable(): mixed}>
     */
    public static function invalidDefinitionProvider(): iterable
    {
        yield 'key not in snake_case' => [static fn () => new EndgameDefinition('First-Mode', 'First Mode')];
        yield 'variant key not in snake_case' => [static fn () => new EndgameVariant('Hard reminder', 'Hard')];
        yield 'three variants (no room in a notification row)' => [static fn () => new EndgameDefinition('mode', 'Mode', [
            new EndgameVariant('a', 'A'), new EndgameVariant('b', 'B'), new EndgameVariant('c', 'C'),
        ])];
        yield 'duplicate variant keys' => [static fn () => new EndgameDefinition('mode', 'Mode', [
            new EndgameVariant('a', 'A'), new EndgameVariant('a', 'Again A'),
        ])];
        yield 'level key not in snake_case' => [static fn () => new EndgameLevel('Very Hard', 'Very Hard')];
        yield 'level key "off"' => [static fn () => new EndgameLevel('off', 'Off')];
        yield 'level key "not_played"' => [static fn () => new EndgameLevel('not_played', 'Not Played')];
        yield 'level key "full_clear"' => [static fn () => new EndgameLevel('full_clear', 'Full Clear')];
        yield 'duplicate level keys' => [static fn () => new EndgameVariant('reminder', 'Mode', levels: [
            new EndgameLevel('easy', 'Easy'), new EndgameLevel('easy', 'Again Easy'),
        ])];
    }

    #[DataProvider('invalidDefinitionProvider')]
    public function testInvalidDeclarationsAreRejected(callable $declare): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $declare();
    }

    public function testDefaultsForAProfileWithoutSettings(): void
    {
        $this->assertSame(
            ['first_mode' => ['reminder' => 'not_played'], 'second_mode' => ['reminder' => 'not_played', 'hard_reminder' => 'off']],
            EndgameSettings::normalize(self::definitions(), null)
        );
    }

    public function testStoredValuesWinOverTheDefaults(): void
    {
        $resolved = EndgameSettings::resolve(self::definitions(), [
            'first_mode' => ['reminder' => 'full_clear'],
            'second_mode' => ['hard_reminder' => 'not_played'],
        ]);

        $this->assertSame(EndgameReminder::FullClear, $resolved['first_mode']['reminder']->reminder);
        $this->assertSame(EndgameReminder::NotPlayed, $resolved['second_mode']['reminder']->reminder);
        $this->assertSame(EndgameReminder::NotPlayed, $resolved['second_mode']['hard_reminder']->reminder);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidSettingsProvider(): iterable
    {
        yield 'not an array' => ['full_clear'];
        yield 'mode not an array' => [['first_mode' => 'full_clear', 'second_mode' => true]];
        yield 'unknown values' => [['first_mode' => ['reminder' => 'always'], 'second_mode' => ['reminder' => 1, 'hard_reminder' => null]]];
        yield 'unknown modes and variants only' => [['third_mode' => ['reminder' => 'full_clear'], 'first_mode' => ['extra' => 'full_clear']]];
    }

    #[DataProvider('invalidSettingsProvider')]
    public function testInvalidOrUnknownEntriesFallBackToTheDefaultsAndAreDropped(mixed $settings): void
    {
        $this->assertSame(
            ['first_mode' => ['reminder' => 'not_played'], 'second_mode' => ['reminder' => 'not_played', 'hard_reminder' => 'off']],
            EndgameSettings::normalize(self::definitions(), $settings)
        );
    }

    /**
     * A mode played at a difficulty the player picks: its levels replace Full Clear.
     *
     * @return list<EndgameDefinition>
     */
    private static function definitionsWithLevels(): array
    {
        return [
            new EndgameDefinition('first_mode', 'First Mode'),
            new EndgameDefinition('level_mode', 'Level Mode', [
                new EndgameVariant('reminder', 'Level Mode', levels: [
                    new EndgameLevel('easy', 'Easy'),
                    new EndgameLevel('normal', 'Normal'),
                    new EndgameLevel('very_hard', 'Very Hard'),
                ]),
            ]),
        ];
    }

    public function testAVariantWithLevels(): void
    {
        $variant = self::definitionsWithLevels()[1]->variants[0];

        $this->assertTrue($variant->hasLevels());
        $this->assertSame(['easy', 'normal', 'very_hard'], array_map(static fn (EndgameLevel $level) => $level->key, $variant->levels));
        $this->assertSame('Normal', $variant->getLevel('normal')?->name);
        $this->assertNull($variant->getLevel('full_clear'));
        $this->assertSame('very_hard', $variant->getTopLevel()?->key);
        $this->assertSame('not_played', $variant->getDefaultTarget()->getValue());

        $withoutLevels = self::definitions()[0]->variants[0];
        $this->assertFalse($withoutLevels->hasLevels());
        $this->assertSame([], $withoutLevels->levels);
        $this->assertNull($withoutLevels->getTopLevel());
    }

    public function testAStoredLevelKeyResolvesToThatLevel(): void
    {
        $target = EndgameSettings::resolve(self::definitionsWithLevels(), ['level_mode' => ['reminder' => 'normal']])['level_mode']['reminder'];

        $this->assertSame(EndgameReminder::FullClear, $target->reminder);
        $this->assertSame('normal', $target->level?->key);
        $this->assertSame('normal', $target->getValue());
    }

    public function testFullClearOnAVariantWithLevelsIsTheTopLevel(): void
    {
        $definitions = self::definitionsWithLevels();
        $target = EndgameSettings::resolve($definitions, ['level_mode' => ['reminder' => 'full_clear']])['level_mode']['reminder'];

        $this->assertSame(EndgameReminder::FullClear, $target->reminder);
        $this->assertSame('very_hard', $target->level?->key);
        // Stored and serialized as the level key
        $this->assertSame(
            ['first_mode' => ['reminder' => 'not_played'], 'level_mode' => ['reminder' => 'very_hard']],
            EndgameSettings::normalize($definitions, ['level_mode' => ['reminder' => 'full_clear']])
        );
        // A default of full_clear is the top level as well
        $variant = new EndgameVariant('reminder', 'Level Mode', EndgameReminder::FullClear, $definitions[1]->variants[0]->levels);
        $this->assertSame('very_hard', $variant->getDefaultTarget()->getValue());
    }

    public function testOffAndNotPlayedWorkAsWithoutLevels(): void
    {
        $definitions = self::definitionsWithLevels();

        foreach (['off' => EndgameReminder::Off, 'not_played' => EndgameReminder::NotPlayed] as $value => $reminder) {
            $target = EndgameSettings::resolve($definitions, ['level_mode' => ['reminder' => $value]])['level_mode']['reminder'];
            $this->assertSame($reminder, $target->reminder);
            $this->assertNull($target->level);
            $this->assertSame($value, $target->getValue());
        }
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function undeclaredLevelProvider(): iterable
    {
        yield 'level of no variant' => ['lunar'];
        yield 'level key in another case' => ['Normal'];
        yield 'not a string' => [2];
    }

    #[DataProvider('undeclaredLevelProvider')]
    public function testAnUndeclaredLevelTakesTheDefault(mixed $value): void
    {
        $this->assertSame(
            ['first_mode' => ['reminder' => 'not_played'], 'level_mode' => ['reminder' => 'not_played']],
            EndgameSettings::normalize(self::definitionsWithLevels(), ['level_mode' => ['reminder' => $value]])
        );
    }

    public function testALevelKeyIsNotAValueOfAVariantWithoutLevels(): void
    {
        $this->assertSame(
            ['first_mode' => ['reminder' => 'not_played'], 'second_mode' => ['reminder' => 'not_played', 'hard_reminder' => 'off']],
            EndgameSettings::normalize(self::definitions(), ['first_mode' => ['reminder' => 'normal']])
        );
    }

    public function testATargetComparesLevelsInTheOrderOfTheVariant(): void
    {
        $variant = self::definitionsWithLevels()[1]->variants[0];
        $target = $variant->findTarget('normal');

        $this->assertFalse($target->isLevelReached('easy'));
        $this->assertTrue($target->isLevelReached('normal'));
        $this->assertTrue($target->isLevelReached('very_hard'));
        $this->assertFalse($target->isLevelReached('lunar'), 'Unknown level');
        $this->assertFalse($target->isLevelReached(null), 'No level completed');
        $this->assertFalse($variant->findTarget('full_clear')->isLevelReached('normal'));
        $this->assertFalse($variant->findTarget('not_played')->isLevelReached('very_hard'), 'No chosen level');
    }

    /**
     * @return iterable<string, array{0: callable(): mixed}>
     */
    public static function invalidTargetProvider(): iterable
    {
        $levels = [new EndgameLevel('easy', 'Easy'), new EndgameLevel('normal', 'Normal')];

        yield 'level with not_played' => [static fn () => new EndgameTarget(EndgameReminder::NotPlayed, $levels[0], $levels)];
        yield 'level not among the levels' => [static fn () => new EndgameTarget(EndgameReminder::FullClear, new EndgameLevel('lunar', 'Lunar'), $levels)];
    }

    #[DataProvider('invalidTargetProvider')]
    public function testInvalidTargetsAreRejected(callable $create): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $create();
    }
}
