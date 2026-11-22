<?php

namespace App\Tests\GamesBundle\Model\Endgame;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameSettings;
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

        $this->assertSame(EndgameReminder::FullClear, $resolved['first_mode']['reminder']);
        $this->assertSame(EndgameReminder::NotPlayed, $resolved['second_mode']['reminder']);
        $this->assertSame(EndgameReminder::NotPlayed, $resolved['second_mode']['hard_reminder']);
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
}
