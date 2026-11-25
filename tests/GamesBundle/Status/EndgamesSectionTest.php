<?php

namespace App\Tests\GamesBundle\Status;

use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Model\Profile\ProfileSettingKind;
use App\GamesBundle\Model\Status\EndgamesData;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\EndgamesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Service\Endgame\EndgameStatusBuilder;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Profile\ProfileSettingsReader;
use App\GamesBundle\Status\Section\EndgamesSection;
use App\Tests\GamesBundle\Service\Endgame\FakeEndgamesGame;
use App\Tests\GamesBundle\Status\Fixtures\FakeEndgamesProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PropertyAccess\PropertyAccess;

class EndgamesSectionTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private MockClock $clock;
    private FakeProfileProvider $provider;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->provider = new FakeProfileProvider();
        $this->provider->settings = [
            new ProfileSetting('endgames', 'Endgames reminder', ProfileSettingKind::Reminder, HasEndgamesInterface::class, 'active', extraFields: ['endgamesSettings']),
        ];
    }

    private function section(): EndgamesSection
    {
        $factory = new GameProfileEmbedFactory($this->clock);

        return new EndgamesSection(
            new EndgameStatusBuilder(),
            new EndgamesPresenter($factory, new DurationFormatter()),
            new ProfileSettingsReader(
                new GameProfileRegistry([$this->provider], $this->createStub(UserRoleResolver::class), ['fake' => 'ROLE_FAKE']),
                PropertyAccess::createPropertyAccessor(),
            ),
            new DurationFormatter(),
            $this->clock,
        );
    }

    private function endgame(string $mode, ?string $endsIn, bool $played = false, bool $maxed = false): GameEndgame
    {
        return new GameEndgame()
            ->setName($mode)
            ->setVariant('reminder')
            ->setUnlocked(true)
            ->setPlayed($played)
            ->setMaxed($maxed)
            ->setEndTime($endsIn !== null ? $this->clock->now()->modify($endsIn) : null)
            ->setFetchedAt($this->clock->now()->modify('-5 minutes'));
    }

    /**
     * @param array<string, list<GameEndgame>|\Throwable> $results
     * @param array<string, array<string, string>>|null   $settings
     */
    private function collect(array $results, ?array $settings = null, bool $fresh = false, ?FakeEndgamesGame &$game = null): EndgamesData
    {
        $game = new FakeEndgamesGame($results);
        $this->provider->services = ['fake_game' => $game];
        $profile = FakeEndgamesProfile::ownedBy(GameProfileRegistryTest::user(1), 1)->setEndgamesSettings($settings);

        return $this->section()->collect($this->provider->createContext($profile, $fresh));
    }

    public function testEveryModeIsReadWithTheTargetsOfTheProfile(): void
    {
        $data = $this->collect(['first' => [$this->endgame('First', '+2 days')]], ['first' => ['reminder' => 'full_clear']], fresh: true, game: $game);

        $this->assertSame(['first', 'second', 'third'], array_column($game->reads, 'mode'));
        $this->assertSame([true, true, true], array_column($game->reads, 'fresh'));
        $this->assertSame(EndgameReminder::FullClear, $data->targets['first']['reminder']->reminder);
        $this->assertSame(EndgameReminder::NotPlayed, $data->targets['second']['reminder']->reminder, 'default target');
        $this->assertEquals($this->clock->now()->modify('-5 minutes'), $data->getFetchedAt());
    }

    public function testTheSectionFailsOnlyWhenEveryModeFailed(): void
    {
        $error = new \RuntimeException('Please login');

        $data = $this->collect(['first' => $error, 'second' => [$this->endgame('Second', '+1 day')], 'third' => $error]);
        $this->assertCount(2, $data->readout->getErrors());

        $this->expectExceptionObject($error);
        $this->collect(['first' => $error, 'second' => $error, 'third' => $error]);
    }

    public function testSummaryCountsTheModesAtTargetAndTheNextEnd(): void
    {
        $profile = FakeEndgamesProfile::ownedBy(GameProfileRegistryTest::user(1), 1);
        $line = fn (array $results, ?array $settings = null): SummaryLine => $this->section()->summarize($profile, $this->collect($results, $settings));

        $this->assertEquals(new SummaryLine('Endgames', '1/2 at target · next ends in 1 day, 6 hr', '🎯', true), $line([
            'first' => [$this->endgame('First', '+5 days', played: true)],
            'second' => [$this->endgame('Second', '+1 day 6 hours')],
            // No current period: not counted
            'third' => [$this->endgame('Third', null)],
        ]));

        $this->assertEquals(new SummaryLine('Endgames', '1/1 at target · 1 unavailable', '🎯'), $line([
            'first' => [$this->endgame('First', '+5 days', played: true)],
            'second' => new \RuntimeException('down'),
            'third' => [$this->endgame('Third', null)],
        ]));

        // A mode the profile turned off is not counted; full clear needs the maximum
        $this->assertEquals(new SummaryLine('Endgames', '0/1 at target · next ends in 5 days, 3 hr', '🎯', true), $line([
            'first' => [$this->endgame('First', '+5 days 3 hours', played: true)],
            'second' => [$this->endgame('Second', '+1 day')],
        ], ['first' => ['reminder' => 'full_clear'], 'second' => ['reminder' => 'off']]));

        // A locked mode has nothing to reach yet: not counted as at target
        $this->assertEquals(new SummaryLine('Endgames', '1/1 at target', '🎯'), $line([
            'first' => [$this->endgame('First', '+5 days', played: true)],
            'second' => [$this->endgame('Second', '+1 day')->setUnlocked(false)],
        ]));

        $this->assertEquals(new SummaryLine('Endgames', 'Nothing to play', '🎯'), $line([]));
    }

    public function testFullViewListsTheModesItCannotShow(): void
    {
        $profile = FakeEndgamesProfile::ownedBy(GameProfileRegistryTest::user(1), 1);
        $data = $this->collect([
            'first' => [$this->endgame('First', '+5 days', played: true)],
            'second' => new \RuntimeException('down'),
            'third' => [$this->endgame('Third', null)],
        ]);

        $embed = $this->section()->present($profile, $data);

        $this->assertSame('Fake Game - Endgames', $embed->getTitle());
        $this->assertSame("Second Mode: Unavailable right now\nThird Mode: no current period", $embed->getDescription());
        $this->assertContains('First Mode', array_map(static fn ($field): ?string => $field->getName(), $embed->getFields()));
    }
}
