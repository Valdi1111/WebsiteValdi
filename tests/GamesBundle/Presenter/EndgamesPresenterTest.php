<?php

namespace App\Tests\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameModeStatus;
use App\GamesBundle\Model\Endgame\EndgameVariant;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Presenter\EndgamesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class EndgamesPresenterTest extends TestCase
{
    private const string FILLER = EndgamesPresenter::FILLER;

    private function present(array $modes, string $now = '2026-03-17 15:00:00'): UniversalEmbed
    {
        $profile = $this->createConfiguredStub(GameProfileInterface::class, [
            'getGameName' => 'Zenless Zone Zero',
            'getGameUid' => '1300000001',
        ]);
        $presenter = new EndgamesPresenter(new GameProfileEmbedFactory(new MockClock()), new DurationFormatter());

        return $presenter->present($profile, $modes, new \DateTimeImmutable($now, new \DateTimeZone('UTC')), 'Endgames');
    }

    /**
     * @return list<array{0: ?string, 1: ?string, 2: ?string}> name, value and icon of the endgame fields, player header excluded
     */
    private static function endgameFields(UniversalEmbed $embed): array
    {
        $fields = array_slice($embed->getFields(), 3);
        foreach ($fields as $field) {
            self::assertTrue($field->isInline());
        }

        return array_map(static fn (EmbedField $field) => [$field->getName(), $field->getValue(), $field->getIcon()], $fields);
    }

    private static function end(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-20 03:59:59', new \DateTimeZone('UTC'));
    }

    private static function shiyuDefense(): EndgameDefinition
    {
        return new EndgameDefinition('shiyu_defense', 'Shiyu Defense');
    }

    private static function deadlyAssault(): EndgameDefinition
    {
        return new EndgameDefinition('deadly_assault', 'Deadly Assault', [
            new EndgameVariant(EndgameVariant::DEFAULT_KEY, 'Deadly Assault'),
            new EndgameVariant('hard_reminder', 'Deadly Assault (Hard)'),
        ]);
    }

    public function testOneRowPerModeWithItsVariants(): void
    {
        $embed = $this->present([
            new EndgameModeStatus(self::shiyuDefense(), [new GameEndgame()->setPlayed(false)], self::end()),
            new EndgameModeStatus(self::deadlyAssault(), [
                new GameEndgame()->setPlayed(true)->addProgressLine('8/9 ★'),
                new GameEndgame()->setVariant('hard_reminder')->setPlayed(true)->setMaxed(true)->addProgressLine('3/3 ★'),
            ], self::end()),
        ]);

        $this->assertSame('Zenless Zone Zero - Endgames', $embed->getTitle());
        $this->assertSame([
            ['Shiyu Defense', 'Not played yet', '⚔️'],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
            ['Deadly Assault', '8/9 ★', '⚔️'],
            ['Deadly Assault (Hard)', '3/3 ★', '✅'],
            ['Ends in', '2 days, 12 hr', '🕒'],
        ], self::endgameFields($embed));
    }

    public function testHiddenLockedAndMissingVariantsAreEmptyColumns(): void
    {
        $embed = $this->present([
            new EndgameModeStatus(self::deadlyAssault(), [
                new GameEndgame()->setPlayed(true)->addProgressLine('8/9 ★'),
                new GameEndgame()->setVariant('hard_reminder')->setUnlocked(false),
            ], self::end(), hiddenVariants: [EndgameVariant::DEFAULT_KEY]),
            new EndgameModeStatus(self::shiyuDefense(), [], self::end()),
        ]);

        $this->assertSame([
            [self::FILLER, self::FILLER, null],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
            [self::FILLER, self::FILLER, null],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
        ], self::endgameFields($embed));
    }

    public function testProgressLinesWithTheirOwnIcons(): void
    {
        $embed = $this->present([
            new EndgameModeStatus(self::shiyuDefense(), [
                new GameEndgame()->setPlayed(true)->addProgressLine('Floor 4: S', '⚔️')->addProgressLine('↳ Stage 1: A'),
            ], self::end()),
            new EndgameModeStatus(self::deadlyAssault(), [new GameEndgame()->setPlayed(true)], self::end()),
        ]);

        $this->assertSame([
            ['Shiyu Defense', "⚔️\u{00A0}\u{00A0}Floor 4: S\n↳ Stage 1: A", null],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
            ['Deadly Assault', 'Played', '⚔️'],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
        ], self::endgameFields($embed));
    }

    public function testEndedPeriod(): void
    {
        $embed = $this->present([new EndgameModeStatus(self::shiyuDefense(), [new GameEndgame()], self::end())], now: '2026-03-21 00:00:00');

        $this->assertSame(['Ends in', '0 sec', '🕒'], self::endgameFields($embed)[2]);
    }

    public function testNoModes(): void
    {
        $this->assertSame([], self::endgameFields($this->present([])));
    }
}
