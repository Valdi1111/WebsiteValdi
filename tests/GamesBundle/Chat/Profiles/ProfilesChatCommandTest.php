<?php

namespace App\Tests\GamesBundle\Chat\Profiles;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\CoreBundle\Service\Chat\ChatPaginator;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Chat\Action\GamesChatAction;
use App\GamesBundle\Chat\ChatProfileSelector;
use App\GamesBundle\Chat\Command\AbstractProfileChatCommand;
use App\GamesBundle\Chat\Command\ProfilesChatCommand;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\ProfileOverviewPresenter;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Profile\ProfileSettingsReader;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\GamesBundle\Service\Status\ProfileStatusRefreshLimiter;
use App\Tests\CoreBundle\Chat\Fixtures\RecordingLogger;
use App\Tests\GamesBundle\Chat\Profiles\Fixtures\ApiFreeGameService;
use App\Tests\GamesBundle\Chat\Profiles\Fixtures\OverviewProfile;
use App\Tests\GamesBundle\Chat\Profiles\Fixtures\OverviewSettings;
use App\Tests\GamesBundle\Status\Fixtures\FakeNotesGameService;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use App\Tests\GamesBundle\Status\Fixtures\OtherFakeGameProfile;
use App\Tests\GamesBundle\Status\GameProfileRegistryTest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * /profiles through the base of the game commands and the "games" button action, as the chat framework runs them:
 * selection by game and profile, pages, buttons. No database, and no game API call.
 */
class ProfilesChatCommandTest extends TestCase
{
    private User $user;
    private FakeProfileProvider $provider;
    private FakeProfileProvider $other;
    private ApiFreeGameService $game;
    private FakeNotesGameService $plainGame;
    private ProfilesChatCommand $command;
    private GamesChatAction $action;

    protected function setUp(): void
    {
        $this->user = GameProfileRegistryTest::user(1);
        $this->game = new ApiFreeGameService();
        $this->plainGame = new FakeNotesGameService();

        $this->provider = new FakeProfileProvider('fake', OverviewProfile::class);
        $this->provider->services = ['fake_game' => $this->game, 'plain_game' => $this->plainGame];
        $this->provider->slugs = ['fake_game' => ['fg', ['fake']], 'plain_game' => ['pg']];
        $this->provider->settings = OverviewSettings::all();

        // A second provider whose profiles cannot be read (unreadable cookie)
        $this->other = new FakeProfileProvider('other', OtherFakeGameProfile::class);
        $this->other->services = ['other_game' => new ApiFreeGameService()];
        $this->other->slugs = ['other_game' => ['og']];
        $this->other->contextError = new \RuntimeException('cookie=secret-token');

        $roles = $this->createStub(UserRoleResolver::class);
        $roles->method('getReachableRoleNames')->willReturn([AbstractProfileChatCommand::ROLE, 'ROLE_FAKE', 'ROLE_OTHER']);
        $registry = new GameProfileRegistry([$this->provider, $this->other], $roles, ['fake' => 'ROLE_FAKE', 'other' => 'ROLE_OTHER']);

        $selector = new ChatProfileSelector($registry, new GameProfileStatusCollector($registry, [], new NullLogger()), 'games.valdi.test');
        $this->command = new ProfilesChatCommand(new ProfileOverviewPresenter(
            new GameProfileEmbedFactory(new MockClock('2026-11-27 10:00:00')),
            $registry,
            new ProfileSettingsReader($registry, PropertyAccess::createPropertyAccessor()),
            new RecordingLogger(),
        ));
        $this->command->setChatServices($selector, new ChatPaginator(new DiscordEmbedTransformer()));

        $this->action = new GamesChatAction(
            new ChatCommandRegistry(new ServiceLocator(['profiles' => fn (): ProfilesChatCommand => $this->command]), $roles),
            $selector,
            new ProfileStatusRefreshLimiter(new RateLimiterFactory(['id' => 'games_status_refresh', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute'], new InMemoryStorage())),
        );
    }

    protected function assertPostConditions(): void
    {
        // Database only: the game services are never asked for data
        $this->assertSame([], $this->game->apiCalls);
        $this->assertSame([], $this->plainGame->notesCalls);
        $this->assertSame(0, $this->plainGame->getterCalls);
    }

    private function addProfile(int $id, string $gameBiz = 'fake_game', bool $active = true): OverviewProfile
    {
        $profile = OverviewProfile::ownedBy($this->user, $id, $gameBiz, active: $active);
        $this->provider->profiles[] = $profile;

        return $profile;
    }

    private function chatUser(): ChatUser
    {
        return new ChatUser($this->user, ChatPlatform::Discord, 'd1');
    }

    /**
     * @param array<string, string> $arguments
     */
    private function ask(array $arguments = []): ChatReply
    {
        return $this->command->execute(new ChatRequest(ChatPlatform::Discord, $this->chatUser(), 'profiles', null, $arguments));
    }

    private function press(ChatActionRef $ref): ChatReply
    {
        $this->assertNull($this->action->authorize($this->chatUser(), $ref));

        return $this->action->handle($this->chatUser(), $ref, 'en');
    }

    /**
     * @return list<string> the Player field of each embed: "(`<uid>`) <nickname>"
     */
    private static function players(ChatReply $reply): array
    {
        return array_map(static fn (UniversalEmbed $embed): string => (string) $embed->getFields()[0]->getValue(), $reply->embeds);
    }

    /**
     * @return array<string, ChatAction> the buttons by label
     */
    private static function buttons(ChatReply $reply): array
    {
        $buttons = [];
        foreach ($reply->actionRows as $row) {
            foreach ($row as $button) {
                $buttons[$button->label] = $button;
            }
        }

        return $buttons;
    }

    public function testEveryActiveProfileOfEveryProviderWithoutButtons(): void
    {
        $this->addProfile(1);
        $this->addProfile(2, 'plain_game');
        $this->addProfile(3, active: false);
        $this->other->profiles[] = new OtherFakeGameProfile(OverviewProfile::ownedBy($this->user, 7, 'other_game'));

        $reply = $this->ask();

        $this->assertSame(['(`8000001`) Player1', '(`8000002`) Player2', '(`8000007`) Player7'], self::players($reply));
        $this->assertSame(['Fake Game - Profile'], array_values(array_unique(array_map(static fn (UniversalEmbed $embed): ?string => $embed->getTitle(), $reply->embeds))));
        $this->assertSame([], $reply->actionRows, 'one page, and no Refresh: nothing is read from the game');
        $this->assertSame([1, 1], [$reply->page, $reply->pageCount]);

        // The unreadable profile is listed with the others, without its error
        $this->assertNull($reply->embeds[0]->getDescription());
        $this->assertSame('⚠️ ' . ProfileOverviewPresenter::SETTINGS_UNAVAILABLE, $reply->embeds[2]->getDescription());
        $this->assertStringNotContainsString('secret', (string) json_encode(array_map(static fn (UniversalEmbed $embed): array => new DiscordEmbedTransformer()->transform($embed)->toArray(), $reply->embeds)));
    }

    public function testSelectionByGame(): void
    {
        $this->addProfile(1);
        $this->addProfile(2, 'plain_game');
        $this->addProfile(3);

        $this->assertSame(['(`8000002`) Player2'], self::players($this->ask(['game' => 'pg'])));
        $this->assertSame(['(`8000001`) Player1', '(`8000003`) Player3'], self::players($this->ask(['game' => 'FAKE'])));
        $this->assertSame('⚠️ Unknown game "zz". Your games: fg, pg.', $this->ask(['game' => 'zz'])->text);
    }

    public function testSelectionByProfile(): void
    {
        $this->addProfile(1);
        $this->addProfile(2, 'plain_game');

        $this->assertSame(['(`8000002`) Player2'], self::players($this->ask(['profile' => 'player2'])));
        $this->assertSame(['(`8000001`) Player1'], self::players($this->ask(['profile' => '8000001'])));
        $this->assertSame(['(`8000002`) Player2'], self::players($this->ask(['profile' => 'fake:2', 'game' => 'pg'])));
        $this->assertSame('⚠️ ' . ChatProfileSelector::NOT_FOUND, $this->ask(['profile' => 'fake:2', 'game' => 'fg'])->text);
    }

    public function testASuspendedProfileShowsWhenAskedByReference(): void
    {
        $this->addProfile(1);
        $this->addProfile(4, active: false);

        $this->assertSame(['(`8000001`) Player1'], self::players($this->ask()), 'only the active profiles by default');

        $reply = $this->ask(['profile' => 'fake:4']);
        $this->assertSame(['(`8000004`) Player4'], self::players($reply));
        $this->assertSame(ProfileOverviewPresenter::SUSPENDED, $reply->embeds[0]->getFields()[3]->getValue());
    }

    public function testManyProfilesArePagedWithTheButtonsOfTheSelection(): void
    {
        for ($id = 1; $id <= 23; ++$id) {
            $this->addProfile($id);
        }
        $expected = array_map(static fn (int $id): string => sprintf('(`800000%d`) Player%d', $id, $id), range(1, 23));

        $reply = $this->ask(['game' => 'fake']);

        $this->assertGreaterThan(1, $reply->pageCount);
        $seen = [];
        for ($page = 1; ; ++$page) {
            $this->assertSame($page, $reply->page);
            array_push($seen, ...self::players($reply));
            $buttons = self::buttons($reply);
            $this->assertArrayNotHasKey('Refresh', $buttons);
            if ($page > 1) {
                // The game as its canonical slug, whatever the user typed
                $this->assertEquals(new ChatActionRef(GamesChatAction::NAME, ['profiles', (string) ($page - 1), '0', 'fg', '', '']), $buttons['Previous']->ref);
                $this->assertSame('◀️', $buttons['Previous']->emoji);
            } else {
                $this->assertArrayNotHasKey('Previous', $buttons);
            }
            if ($page === $reply->pageCount) {
                $this->assertArrayNotHasKey('Next', $buttons);
                break;
            }
            $this->assertEquals(new ChatActionRef(GamesChatAction::NAME, ['profiles', (string) ($page + 1), '0', 'fg', '', '']), $buttons['Next']->ref);
            $this->assertSame('▶️', $buttons['Next']->emoji);

            $reply = $this->press($buttons['Next']->ref);
        }
        $this->assertSame($expected, $seen, 'every profile once, in order');

        // And back
        $previous = $this->press(self::buttons($reply)['Previous']->ref);
        $this->assertSame($reply->page - 1, $previous->page);
    }

    public function testARefreshButtonIsNeverValidForProfiles(): void
    {
        $this->addProfile(1);
        $forged = new ChatActionRef(GamesChatAction::NAME, ['profiles', '1', '1', 'fg', '', '']);

        $this->assertFalse($this->command->canRefresh());
        $this->assertSame(ChatDispatcher::INVALID_ACTION, $this->action->authorize($this->chatUser(), $forged));
        $this->assertSame('⚠️ ' . ChatDispatcher::INVALID_ACTION, $this->action->handle($this->chatUser(), $forged, 'en')->text);
    }

    public function testAutocompleteOffersEveryGameAndProfile(): void
    {
        $this->addProfile(1);
        $this->addProfile(2, 'plain_game');
        // Unreadable: still offered, as for every command
        $this->other->profiles[] = new OtherFakeGameProfile(OverviewProfile::ownedBy($this->user, 7, 'other_game'));

        $games = $this->command->autocomplete($this->chatUser(), null, AbstractProfileChatCommand::OPTION_GAME, '', []);
        $profiles = $this->command->autocomplete($this->chatUser(), null, AbstractProfileChatCommand::OPTION_PROFILE, '', [AbstractProfileChatCommand::OPTION_GAME => 'fg']);

        $this->assertSame(['fg', 'pg', 'og'], array_map(static fn ($choice): string => (string) $choice->value, $games));
        $this->assertSame(['fake:1'], array_map(static fn ($choice): string => (string) $choice->value, $profiles));
    }

    public function testCommandMetadata(): void
    {
        $this->assertSame('profiles', $this->command->getName());
        $attribute = new \ReflectionClass(ProfilesChatCommand::class)->getAttributes(AsChatCommand::class)[0]->newInstance();
        $this->assertSame('Show your game profiles and their reminders', $attribute->description);
        $this->assertSame(AbstractProfileChatCommand::ROLE, $attribute->role);
    }
}
