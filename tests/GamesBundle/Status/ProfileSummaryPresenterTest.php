<?php

namespace App\Tests\GamesBundle\Status;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Model\Status\SectionResult;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Status\Section\DailiesSection;
use App\GamesBundle\Status\Section\StaminaSection;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class ProfileSummaryPresenterTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private MockClock $clock;
    private StaminaSection $stamina;
    private DailiesSection $dailies;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $factory = new GameProfileEmbedFactory($this->clock);
        $this->stamina = new StaminaSection(new StaminaPresenter($factory, new DurationFormatter()), new DurationFormatter(), $this->clock);
        $this->dailies = new DailiesSection(new DailiesPresenter($factory));
    }

    private function presenter(): ProfileSummaryPresenter
    {
        return new ProfileSummaryPresenter(new GameProfileEmbedFactory($this->clock), new DurationFormatter());
    }

    /**
     * @param array<string, SectionResult> $sections
     */
    private static function profileStatus(array $sections, ?\DateTimeImmutable $fetchedAt = null, ?AccountHealth $health = null, ?\Throwable $error = null): GameProfileStatus
    {
        return new GameProfileStatus(
            FakeGameProfile::ownedBy(GameProfileRegistryTest::user(1), 1),
            new GameProfileRef('fake', 1),
            new GameDescriptor('fake', 'fake_game', 'fg', 'Fake Game'),
            $health ?? AccountHealth::ok(),
            $sections,
            $fetchedAt,
            $error,
        );
    }

    /**
     * @return list<array{0: ?string, 1: ?string, 2: ?string}>
     */
    private static function sectionFields(UniversalEmbed $embed): array
    {
        return array_map(
            static fn (EmbedField $field): array => [$field->getName(), $field->getValue(), $field->getIcon()],
            array_slice($embed->getFields(), 3)
        );
    }

    public function testOneLinePerSectionWithTheItemsToActOnMarked(): void
    {
        $fetchedAt = $this->clock->now()->modify('-4 minutes');
        $status = self::profileStatus([
            'stamina' => SectionResult::success($this->stamina, new GameNotesStamina()->setCurrentStamina(200)->setMaxStamina(200)->setStaminaRecoverTime(0)),
            'dailies' => SectionResult::success($this->dailies, new GameNotesDailies()->addDaily(new GameNotesProgressMetric()->setName('Daily Tasks')->setCurrentValue(4)->setMaxValue(4))),
        ], $fetchedAt);

        $embed = $this->presenter()->present($status);

        $this->assertSame('Fake Game - Status', $embed->getTitle());
        $this->assertSame('Data from 4 min ago.', $embed->getDescription());
        $this->assertEquals($fetchedAt, $embed->getTimestamp(), 'the time of the data, not of the message');
        $this->assertSame([
            ['Stamina', '🔔 200/200 · full', '🔋'],
            ['Dailies', 'Done', '📅'],
        ], self::sectionFields($embed));
    }

    public function testFailedSectionShowsAGenericLine(): void
    {
        $status = self::profileStatus([
            'stamina' => SectionResult::failure($this->stamina, new \RuntimeException('HTTP 500 at https://example.test/secret?token=abc')),
        ]);

        $embed = $this->presenter()->present($status);

        $this->assertSame([['Stamina', '⚠️ Unavailable right now', '🔋']], self::sectionFields($embed));
        $this->assertStringNotContainsString('secret', json_encode([$embed->getDescription(), self::sectionFields($embed)], JSON_THROW_ON_ERROR));
    }

    public function testSectionThatCannotBeLaidOutShowsAGenericLineAndTheOthersStay(): void
    {
        $status = self::profileStatus([
            // Unexpected data: summarize() throws
            'stamina' => SectionResult::success($this->stamina, new \stdClass()),
            'dailies' => SectionResult::success($this->dailies, new GameNotesDailies()->addDaily(new GameNotesProgressMetric()->setName('Daily Tasks')->setCurrentValue(4)->setMaxValue(4))),
        ]);

        $embed = $this->presenter()->present($status);

        $this->assertSame([
            ['Stamina', '⚠️ Unavailable right now', '🔋'],
            ['Dailies', 'Done', '📅'],
        ], self::sectionFields($embed));
    }

    public function testAccountProblemAndUnreadableProfile(): void
    {
        $status = self::profileStatus([], health: AccountHealth::problem('Log in again on the site.'), error: new \RuntimeException('cookie'));

        $embed = $this->presenter()->present($status);

        $this->assertSame("⚠️ Log in again on the site.\n⚠️ The data of this profile could not be read right now.", $embed->getDescription());
        $this->assertSame([], self::sectionFields($embed));
    }

    public function testProfileWithoutLiveData(): void
    {
        $embed = $this->presenter()->present(self::profileStatus([]));

        $this->assertSame('Nothing to show for this game.', $embed->getDescription());
        $this->assertEquals($this->clock->now(), $embed->getTimestamp());
    }

    public function testRecentDataHasNoAgeLine(): void
    {
        $embed = $this->presenter()->present(self::profileStatus([
            'stamina' => SectionResult::success($this->stamina, new GameNotesStamina()->setCurrentStamina(10)->setMaxStamina(200)),
        ], $this->clock->now()->modify('-30 seconds')));

        $this->assertNull($embed->getDescription());
    }
}
