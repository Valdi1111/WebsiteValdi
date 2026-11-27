<?php

namespace App\Tests\GamesBundle\Chat\Profiles;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Model\Profile\ProfileSettingKind;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\ProfileOverviewPresenter;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Profile\ProfileSettingsReader;
use App\Tests\CoreBundle\Chat\Fixtures\RecordingLogger;
use App\Tests\GamesBundle\Chat\Profiles\Fixtures\ApiFreeGameService;
use App\Tests\GamesBundle\Chat\Profiles\Fixtures\OverviewProfile;
use App\Tests\GamesBundle\Chat\Profiles\Fixtures\OverviewSettings;
use App\Tests\GamesBundle\Status\Fixtures\FakeNotesGameService;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use App\Tests\GamesBundle\Status\GameProfileRegistryTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PropertyAccess\PropertyAccess;

class ProfileOverviewPresenterTest extends TestCase
{
    private FakeProfileProvider $provider;
    private ApiFreeGameService $game;
    private FakeNotesGameService $plainGame;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->game = new ApiFreeGameService();
        $this->plainGame = new FakeNotesGameService();
        $this->provider = new FakeProfileProvider('fake', OverviewProfile::class);
        $this->provider->services = ['fake_game' => $this->game, 'plain_game' => $this->plainGame];
        $this->provider->settings = OverviewSettings::all();
        $this->logger = new RecordingLogger();
    }

    protected function assertPostConditions(): void
    {
        // Every test reads the database side only
        $this->assertSame([], $this->game->apiCalls);
        $this->assertSame([], $this->plainGame->notesCalls);
        $this->assertSame(0, $this->plainGame->getterCalls);
    }

    private function presenter(): ProfileOverviewPresenter
    {
        $registry = new GameProfileRegistry([$this->provider], $this->createStub(UserRoleResolver::class), ['fake' => 'ROLE_FAKE']);

        return new ProfileOverviewPresenter(
            new GameProfileEmbedFactory(new MockClock('2026-11-27 10:00:00')),
            $registry,
            new ProfileSettingsReader($registry, PropertyAccess::createPropertyAccessor()),
            $this->logger,
        );
    }

    private static function profile(int $id = 1, string $gameBiz = 'fake_game', bool $active = true): OverviewProfile
    {
        return OverviewProfile::ownedBy(GameProfileRegistryTest::user(1), $id, $gameBiz, active: $active);
    }

    /**
     * @return array<string, string> the fields after the header, value by name
     */
    private static function fields(UniversalEmbed $embed): array
    {
        $fields = [];
        foreach (array_slice($embed->getFields(), GameProfileEmbedFactory::HEADER_FIELD_COUNT) as $field) {
            $fields[(string) $field->getName()] = (string) $field->getValue();
        }

        return $fields;
    }

    private static function field(UniversalEmbed $embed, string $name): EmbedField
    {
        foreach ($embed->getFields() as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }

        self::fail(sprintf('No field "%s".', $name));
    }

    public function testEveryFieldOfAProfileAfterTheHeader(): void
    {
        $profile = self::profile()
            ->setStaminaThreshold(180)
            ->setEndgamesSettings(['abyss' => ['reminder' => 'full_clear'], 'assault' => ['hard_reminder' => 'not_played'], 'theater' => ['reminder' => 'normal']])
            ->setNotificationPlatforms(['discord', 'Telegram', 'DISCORD']);
        $profile->checkIn = true;
        $profile->staminaCheck = true;
        $profile->currencyCheck = true;
        $profile->dailiesCheck = true;
        $profile->endgamesCheck = true;
        $profile->syncPulls = true;

        $embed = $this->presenter()->present($profile);

        $this->assertSame('Fake Game - Profile', $embed->getTitle());
        $this->assertNull($embed->getDescription());
        $this->assertSame(['Player', 'Region', 'Rank'], array_map(static fn (EmbedField $field): ?string => $field->getName(), array_slice($embed->getFields(), 0, 3)));
        $this->assertSame('(`8000001`) Player1', $embed->getFields()[0]->getValue());
        $this->assertSame([
            'Status' => 'Active',
            'Account' => 'OK',
            // Weeklies is on, but the game has none
            'Reminders' => "Stamina reminder (≥ 180)\nRealm currency reminder (when full)\nDailies reminder\nEndgames reminder",
            'Automations' => 'Daily check-in',
            'Sync' => 'Pulls sync',
            'Endgame targets' => "Abyss: Full Clear\nAssault: Not Played\nAssault (Hard): Not Played\nTheater: Normal",
            'Notifications' => "Discord\nTelegram",
        ], self::fields($embed));
        $this->assertTrue(self::field($embed, 'Status')->isInline());
        $this->assertTrue(self::field($embed, 'Account')->isInline());
        $this->assertFalse(self::field($embed, 'Reminders')->isInline());
        $this->assertSame('✅', self::field($embed, 'Status')->getIcon());
    }

    /**
     * @return iterable<string, array{?int, int, string, string}>
     */
    public static function thresholds(): iterable
    {
        yield 'set' => [180, 2400, 'Stamina reminder (≥ 180)', 'Realm currency reminder (≥ 2400)'];
        yield 'zero is a threshold' => [0, 0, 'Stamina reminder (≥ 0)', 'Realm currency reminder (≥ 0)'];
        yield 'negative means when full' => [-1, -1, 'Stamina reminder (when full)', 'Realm currency reminder (when full)'];
        yield 'null means when full' => [null, -5, 'Stamina reminder (when full)', 'Realm currency reminder (when full)'];
    }

    #[DataProvider('thresholds')]
    public function testThresholdOfTheReminders(?int $stamina, ?int $currency, string $staminaLine, string $currencyLine): void
    {
        $profile = self::profile()->setStaminaThreshold($stamina);
        $profile->staminaCheck = true;
        $profile->currencyCheck = true;
        $profile->currencyThreshold = $currency;
        // No threshold field: never a suffix, whatever the other thresholds
        $profile->dailiesCheck = true;

        $this->assertSame(implode("\n", [$staminaLine, $currencyLine, 'Dailies reminder']), self::fields($this->presenter()->present($profile))['Reminders']);
    }

    public function testNothingTurnedOnReadsNone(): void
    {
        $profile = self::profile();
        $profile->weekliesCheck = true;

        $fields = self::fields($this->presenter()->present($profile));

        $this->assertSame(ProfileOverviewPresenter::NONE, $fields['Reminders'], 'weeklies is not a setting of the game');
        $this->assertSame(ProfileOverviewPresenter::NONE, $fields['Automations']);
        $this->assertSame(ProfileOverviewPresenter::NONE, $fields['Sync']);
        $this->assertSame(ProfileOverviewPresenter::NONE, $fields['Notifications']);
    }

    public function testEndgameTargetsTakeTheDefaultsOfTheGameForWhatIsNotStored(): void
    {
        $defaults = "Abyss: Not Played\nAssault: Not Played\nAssault (Hard): Off\nTheater: Not Played";

        // Shown with the endgames reminder off too: /endgames measures the progress against them
        $this->assertSame($defaults, self::fields($this->presenter()->present(self::profile()))['Endgame targets']);
        $this->assertSame($defaults, self::fields($this->presenter()->present(self::profile()->setEndgamesSettings([])))['Endgame targets']);

        $partial = self::profile()->setEndgamesSettings([
            'assault' => ['reminder' => 'not_a_target', 'hard_reminder' => 'full_clear'],
            // Full Clear on a variant with levels is its top level
            'theater' => ['reminder' => 'full_clear'],
            'removed_mode' => ['reminder' => 'off'],
        ]);
        $this->assertSame(
            "Abyss: Not Played\nAssault: Not Played\nAssault (Hard): Full Clear\nTheater: Visionary",
            self::fields($this->presenter()->present($partial))['Endgame targets'],
        );

        $off = self::profile()->setEndgamesSettings(['abyss' => ['reminder' => 'off'], 'theater' => ['reminder' => 'off']]);
        $this->assertStringContainsString("Abyss: Off\n", self::fields($this->presenter()->present($off))['Endgame targets']);
        $this->assertStringEndsWith('Theater: Off', self::fields($this->presenter()->present($off))['Endgame targets']);
    }

    public function testAGameWithoutEndgamesNorSyncHasNeitherField(): void
    {
        $profile = self::profile(2, 'plain_game');
        $profile->staminaCheck = true;
        $profile->syncPulls = true;

        $fields = self::fields($this->presenter()->present($profile));

        $this->assertSame(['Status', 'Account', 'Reminders', 'Automations', 'Notifications'], array_keys($fields));
        $this->assertSame('Stamina reminder (when full)', $fields['Reminders']);
    }

    public function testAProviderWithoutSettingsStillShowsTheProfile(): void
    {
        $this->provider->settings = [];

        $fields = self::fields($this->presenter()->present(self::profile()));

        // The game still declares its endgames, so their targets (the defaults) show
        $this->assertSame(['Status', 'Account', 'Reminders', 'Automations', 'Endgame targets', 'Notifications'], array_keys($fields));
        $this->assertSame(ProfileOverviewPresenter::NONE, $fields['Reminders']);
    }

    public function testUnreadableProfileShowsWhatNeedsNoContextWithoutTheError(): void
    {
        $this->provider->contextError = new \RuntimeException('cookie=secret-token');
        $this->provider->health = AccountHealth::problem('The HoYoLAB session could not be refreshed: log in again on the site.');
        $profile = self::profile()->setNotificationPlatforms(['telegram']);
        $profile->staminaCheck = true;

        $embed = $this->presenter()->present($profile);

        $this->assertSame('⚠️ ' . ProfileOverviewPresenter::SETTINGS_UNAVAILABLE, $embed->getDescription());
        $this->assertSame([
            'Status' => 'Active',
            'Account' => 'The HoYoLAB session could not be refreshed: log in again on the site.',
            'Notifications' => 'Telegram',
        ], self::fields($embed));
        $this->assertSame('⚠️', self::field($embed, 'Account')->getIcon());
        $this->assertStringNotContainsString('secret', (string) json_encode(new DiscordEmbedTransformer()->transform($embed)->toArray()));

        $this->assertCount(1, $this->logger->records);
        $this->assertSame(['fake:1', \RuntimeException::class], [$this->logger->records[0][2]['profile'], $this->logger->records[0][2]['error']]);
        $this->assertStringNotContainsString('secret', $this->logger->dump());
    }

    public function testSuspendedProfileKeepsItsSettings(): void
    {
        $profile = self::profile(active: false);
        $profile->staminaCheck = true;

        $embed = $this->presenter()->present($profile);

        $this->assertSame(ProfileOverviewPresenter::SUSPENDED, self::fields($embed)['Status']);
        $this->assertStringStartsWith('Suspended', ProfileOverviewPresenter::SUSPENDED);
        $this->assertSame('⏸️', self::field($embed, 'Status')->getIcon());
        $this->assertSame('Stamina reminder (when full)', self::fields($embed)['Reminders']);
    }

    public function testAccountProblemOfAReadableProfile(): void
    {
        $this->provider->health = AccountHealth::problem('The login has expired: log in again on the site.');

        $embed = $this->presenter()->present(self::profile());

        $this->assertNull($embed->getDescription());
        $this->assertSame('The login has expired: log in again on the site.', self::fields($embed)['Account']);
    }

    public function testLongListsAreCutAtAWholeLineWithinTheFieldLimit(): void
    {
        $settings = [];
        for ($i = 1; $i <= 60; ++$i) {
            $settings[] = new ProfileSetting('reminder_' . $i, sprintf('Reminder number %02d with a long label', $i), ProfileSettingKind::Reminder, HasStaminaInterface::class, 'staminaCheck');
        }
        $this->provider->settings = $settings;
        $profile = self::profile();
        $profile->staminaCheck = true;

        $embed = $this->presenter()->present($profile);
        $lines = explode("\n", self::fields($embed)['Reminders']);

        $more = (int) preg_replace('/\D/', '', (string) array_pop($lines));
        $this->assertGreaterThan(1, count($lines));
        $this->assertSame(60, count($lines) + $more);
        $this->assertSame(sprintf('Reminder number %02d with a long label', count($lines)), end($lines), 'cut at a whole line');
        // As Discord counts it: the icon is prepended to the value
        foreach (new DiscordEmbedTransformer()->transform($embed)->toArray()['fields'] as $field) {
            $this->assertLessThanOrEqual(DiscordEmbedLimiter::MAX_FIELD_VALUE, mb_strlen($field['value']));
        }
    }
}
