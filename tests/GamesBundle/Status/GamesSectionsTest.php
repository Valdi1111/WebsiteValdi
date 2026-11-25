<?php

namespace App\Tests\GamesBundle\Status;

use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesMetricCheckType;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Presenter\WeekliesPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Status\Section\DailiesSection;
use App\GamesBundle\Status\Section\StaminaSection;
use App\GamesBundle\Status\Section\WeekliesSection;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeNotesGameService;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class GamesSectionsTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private static function factory(): GameProfileEmbedFactory
    {
        return new GameProfileEmbedFactory(new MockClock(self::NOW));
    }

    private static function stamina(): StaminaSection
    {
        return new StaminaSection(new StaminaPresenter(self::factory(), new DurationFormatter()), new DurationFormatter(), new MockClock(self::NOW));
    }

    private static function profile(int $threshold = -1): FakeGameProfile
    {
        return FakeGameProfile::ownedBy(GameProfileRegistryTest::user(1), 1)->setStaminaThreshold($threshold);
    }

    private static function context(object $service, bool $withAccountData = true): GameProfileContext
    {
        $provider = new FakeProfileProvider(withAccountData: $withAccountData);
        $provider->services = ['fake_game' => $service];

        return $provider->createContext(self::profile());
    }

    public function testNotesSectionsNeedTheCapabilityAndTheAccountData(): void
    {
        $sections = [self::stamina(), new DailiesSection(new DailiesPresenter(self::factory())), new WeekliesSection(new WeekliesPresenter(self::factory()))];
        // Stamina and dailies, no weeklies
        $service = new FakeNotesGameService();

        $this->assertSame([true, true, false], array_map(static fn ($section): bool => $section->supports(self::context($service)), $sections));
        $this->assertSame([false, false, false], array_map(static fn ($section): bool => $section->supports(self::context($service, false)), $sections));
        $this->assertFalse(self::stamina()->supports(new GameProfileContext(
            self::profile(),
            new GameProfileRef('fake', 1),
            new GameDescriptor('fake', 'fake_game', 'fg', 'Fake Game'),
            new \stdClass(),
            null,
        )));
        $this->assertTrue(self::stamina()->usesNotes());
    }

    public function testStaminaIsCollectedAndPresentedWithAQueryTitle(): void
    {
        $section = self::stamina();
        $data = $section->collect(self::context(new FakeNotesGameService()));

        $this->assertSame(120, $data->getCurrentStamina());
        $this->assertSame('Fake Game - Stamina', $section->present(self::profile(), $data)->getTitle());
    }

    public function testStaminaSummary(): void
    {
        $now = new MockClock(self::NOW)->now();
        $section = self::stamina();
        $line = static fn (GameNotesStamina $data, int $threshold = -1): SummaryLine => $section->summarize(self::profile($threshold), $data);

        $notFull = new GameNotesStamina()->setCurrentStamina(120)->setMaxStamina(200)->setFullAt($now->modify('+5 hours 20 minutes 10 seconds'));
        $this->assertEquals(new SummaryLine('Stamina', '120/200 · full in 5 hr, 20 min', '🔋'), $line($notFull));
        $this->assertTrue($line($notFull, 100)->attention, 'at the threshold of the profile');

        $full = new GameNotesStamina()->setCurrentStamina(200)->setMaxStamina(200)->setStaminaRecoverTime(0);
        $this->assertEquals(new SummaryLine('Stamina', '200/200 · full', '🔋', true), $line($full));

        $noTime = new GameNotesStamina()->setCurrentStamina(10)->setMaxStamina(200);
        $this->assertSame('10/200', $line($noTime)->value);

        $this->assertEquals(new SummaryLine('Stamina', 'Unknown', '🔋'), $line(new GameNotesStamina()));

        // Cached notes fetched before the stamina was full: full by now
        $stale = new GameNotesStamina()->setCurrentStamina(198)->setMaxStamina(200)->setFullAt($now->modify('-1 minute'));
        $this->assertEquals(new SummaryLine('Stamina', '200/200 · full', '🔋', true), $line($stale));
        // The full view says the same, not the count of the read
        $fields = $section->present(self::profile(), $stale)->getFields();
        $this->assertSame('200/200', $fields[GameProfileEmbedFactory::HEADER_FIELD_COUNT]->getValue());
        $this->assertSame('120/200', $section->present(self::profile(), $notFull)->getFields()[GameProfileEmbedFactory::HEADER_FIELD_COUNT]->getValue());
    }

    public function testMetricsSummaries(): void
    {
        $dailies = new DailiesSection(new DailiesPresenter(self::factory()));
        $weeklies = new WeekliesSection(new WeekliesPresenter(self::factory()));
        $metric = static fn (string $name, int $current, int $max): GameNotesProgressMetric => new GameNotesProgressMetric()->setName($name)->setCurrentValue($current)->setMaxValue($max);

        $pending = new GameNotesDailies()
            ->addDaily($metric('Daily Tasks', 2, 4))
            ->addDaily($metric('Synthesis', 1, 1))
            ->addDaily($metric('Locked Event', 0, 1)->setUnlocked(false))
            ->addDaily($metric('Engagement', 100, 400));
        $this->assertEquals(new SummaryLine('Dailies', 'Daily Tasks 2/4, Engagement 100/400', '📅', true), $dailies->summarize(self::profile(), $pending));

        $done = new GameNotesWeeklies()->addWeekly($metric('Resin Discounts', 0, 3)->setCheckType(GameNotesMetricCheckType::CURRENT_EQUALS_ZERO));
        $this->assertEquals(new SummaryLine('Weeklies', 'Done', '📆'), $weeklies->summarize(self::profile(), $done));

        $this->assertEquals(new SummaryLine('Dailies', 'None', '📅'), $dailies->summarize(self::profile(), new GameNotesDailies()));
    }
}
