<?php

namespace App\Tests\GamesBundle\Chat;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\GamesBundle\Chat\Action\GamesChatAction;
use App\GamesBundle\Chat\ChatProfileSelector;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\Tests\GamesBundle\Chat\Fixtures\ChatGameProfile;
use App\Tests\GamesBundle\Chat\Fixtures\GamesChatKernelTrait;
use App\Tests\GamesBundle\Chat\Fixtures\GamesChatWorld;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The game and profile options of the game chat commands, over the profiles of GamesChatWorld and the real sections:
 * what a typed option selects, what it refuses, and the autocomplete suggestions.
 */
class ChatProfileSelectorTest extends KernelTestCase
{
    use GamesChatKernelTrait;

    private User $user;

    protected function setUp(): void
    {
        $this->bootGamesChat();
        $this->user = $this->addUser(42);
    }

    private function selector(): ChatProfileSelector
    {
        return static::getContainer()->get(ChatProfileSelector::class);
    }

    private function select(?string $game, ?string $profile): ChatProfileSelection
    {
        $selection = $this->selector()->select($this->user, $game, $profile);
        $this->assertInstanceOf(ChatProfileSelection::class, $selection, is_string($selection) ? $selection : '');

        return $selection;
    }

    /**
     * @return list<string>
     */
    private static function refs(ChatProfileSelection $selection): array
    {
        return array_map(strval(...), $selection->refs);
    }

    /**
     * @param list<ChatChoice> $choices
     * @return array<string, string|int> value by name
     */
    private static function choices(array $choices): array
    {
        return array_column(array_map(static fn (ChatChoice $choice): array => ['name' => $choice->name, 'value' => $choice->value], $choices), 'value', 'name');
    }

    /**
     * @param list<ChatChoice> $choices
     * @return list<string|int>
     */
    private static function values(array $choices): array
    {
        return array_map(static fn (ChatChoice $choice): string|int => $choice->value, $choices);
    }

    public function testWithoutOptionsEveryActiveProfileOfTheGrantedProviders(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->profile($this->user, 2, 'checkin_game');
        $this->world->otherProfile($this->user, 3);
        $this->world->fake->profiles[] = ChatGameProfile::ownedBy($this->user, 4, 'full_game', 42, active: false);

        $selection = $this->select(null, '  ');

        $this->assertNull($selection->game);
        $this->assertNull($selection->profile);
        // The "other" provider needs a role the user lacks; inactive profiles never show
        $this->assertSame(['fake:1', 'fake:2'], self::refs($selection));
        $this->assertSame([1, 2], array_map(static fn ($profile): ?int => $profile->getId(), $selection->profiles));
    }

    public function testGameBySlugOrAliasIgnoringCase(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->profile($this->user, 2, 'checkin_game');
        $this->world->profile($this->user, 3);

        $selection = $this->select('FullGame', null);

        $this->assertSame('full', $selection->game?->slug);
        $this->assertNull($selection->profile);
        $this->assertSame(['fake:1', 'fake:3'], self::refs($selection));
        $this->assertSame(['fake:2'], self::refs($this->select(' ci ', null)));
    }

    public function testUnknownGameListsTheGamesOfTheUser(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->profile($this->user, 2, 'checkin_game');
        $this->world->profile($this->user, 3);

        $this->assertSame('Unknown game "hsr". Your games: full, ci.', $this->selector()->select($this->user, 'hsr', null));
        // A game of a provider the user has no role for is unknown too
        $this->assertSame('Unknown game "og". Your games: full, ci.', $this->selector()->select($this->user, 'og', null));
        // What the user typed is repeated within reason
        $this->assertSame(sprintf('Unknown game "%s…". Your games: full, ci.', str_repeat('x', 49)), $this->selector()->select($this->user, str_repeat('x', 80), null));
    }

    public function testNoProfileAtAllOrNoneOfTheGame(): void
    {
        $domain = static::getContainer()->getParameter('games.domain_name');
        $this->assertSame(sprintf('You have no active game profile yet. Add one on https://%s/', $domain), $this->selector()->select($this->user, null, null));
        $this->assertSame(sprintf('You have no active game profile yet. Add one on https://%s/', $domain), $this->selector()->select($this->user, 'full', 'fake:1'));

        $this->world->profile($this->user, 1);
        $this->assertSame('You have no active profile of Game plain_game.', $this->selector()->select($this->user, 'plain', null));
    }

    public function testProfileByReferenceUidOrNicknameIgnoringCase(): void
    {
        $this->world->profile($this->user, 1, nickname: 'Nelly', uid: '700000001');
        $this->world->profile($this->user, 2, 'checkin_game', nickname: 'Ruby', uid: '700000002');

        foreach (['fake:2', '700000002', 'ruby', 'RUBY'] as $typed) {
            $selection = $this->select(null, $typed);
            $this->assertSame(['fake:2'], self::refs($selection), $typed);
            // Canonical: the reference and the game of the profile, whatever was typed
            $this->assertEquals(new GameProfileRef('fake', 2), $selection->profile, $typed);
            $this->assertSame('ci', $selection->game?->slug, $typed);
        }

        // Narrowed by the game when both are given
        $this->assertSame(['fake:1'], self::refs($this->select('full', 'nelly')));
        $this->assertSame('No profile "nelly" among yours.', $this->selector()->select($this->user, 'ci', 'nelly'));
        $this->assertSame('No profile "Nobody" among yours.', $this->selector()->select($this->user, null, 'Nobody'));
    }

    public function testAmbiguousNicknameIsRefusedWithTheCandidates(): void
    {
        $this->world->profile($this->user, 1, nickname: 'Nelly', uid: '700000001');
        $this->world->profile($this->user, 2, 'checkin_game', nickname: 'nelly', uid: '700000002');

        $this->assertSame(
            'Several profiles match "Nelly": Full Game · Nelly (700000001); Checkin Game · nelly (700000002). Choose one by its UID, or give the game too.',
            $this->selector()->select($this->user, null, 'Nelly'),
        );
        // No guess, but the game or the UID tell them apart
        $this->assertSame(['fake:2'], self::refs($this->select('ci', 'Nelly')));
        $this->assertSame(['fake:1'], self::refs($this->select(null, '700000001')));
    }

    public function testProfileOfAnotherUserOrGameOrProviderIsRefused(): void
    {
        $this->world->profile($this->user, 1);
        $someoneElse = $this->addUser(7);
        $this->world->profile($someoneElse, 9, nickname: 'Theirs', uid: '700000009');
        $this->world->otherProfile($this->user, 3);

        $this->assertSame(ChatProfileSelector::NOT_FOUND, $this->selector()->select($this->user, null, 'fake:9'));
        $this->assertSame('No profile "700000009" among yours.', $this->selector()->select($this->user, null, '700000009'));
        $this->assertSame('No profile "theirs" among yours.', $this->selector()->select($this->user, null, 'theirs'));
        // Own profile, other game
        $this->assertSame(ChatProfileSelector::NOT_FOUND, $this->selector()->select($this->user, 'ci', 'fake:1'));
        // Own profile, provider without the role
        $this->assertSame(ChatProfileSelector::NOT_FOUND, $this->selector()->select($this->user, null, 'other:3'));
        $this->assertSame(ChatProfileSelector::NOT_FOUND, $this->selector()->select($this->user, null, 'nope:1'));
    }

    public function testButtonRebuildsTheSameSelectionFromItsCanonicalForm(): void
    {
        $this->world->profile($this->user, 1, nickname: 'Nelly');
        $this->world->profile($this->user, 2, 'checkin_game');
        $this->world->profile($this->user, 3);

        foreach ([[null, null], ['FULLGAME', null], [null, 'nelly'], ['full', '8000003']] as [$game, $profile]) {
            $typed = $this->select($game, $profile);
            [, , , $slug, $provider, $id] = GamesChatAction::ref('stamina', 1, false, $typed)->args;

            $rebuilt = $this->selector()->fromButton($this->user, $slug, $provider, $id);

            $this->assertEquals($typed, $rebuilt, sprintf('game %s, profile %s', $game ?? '-', $profile ?? '-'));
        }
    }

    public function testButtonWithAMalformedProfileIsRefused(): void
    {
        $this->world->profile($this->user, 1);

        foreach ([['', '1'], ['fake', ''], ['fake', 'x1'], ['fake', '-1'], ['fake', '0'], ['Fake', '1'], ['fa:ke', '1']] as [$provider, $id]) {
            $this->assertSame(ChatProfileSelector::NOT_FOUND, $this->selector()->fromButton($this->user, '', $provider, $id), $provider . ':' . $id);
        }
        $this->assertSame('Unknown game "nope". Your games: full.', $this->selector()->fromButton($this->user, 'nope', '', ''));
    }

    public function testGameSuggestionsAreTheGamesWithTheSectionsOfTheCommand(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->profile($this->user, 2, 'checkin_game');
        $this->world->profile($this->user, 3, 'plain_game');
        $this->world->profile($this->user, 4);

        $selector = $this->selector();
        $this->assertSame(['Game full_game' => 'full'], self::choices($selector->suggestGames($this->user, ['stamina'], '')));
        $this->assertSame(['Game full_game' => 'full', 'Game checkin_game' => 'ci'], self::choices($selector->suggestGames($this->user, ['stamina', 'check_in'], '')));
        $this->assertSame(['Game full_game' => 'full', 'Game checkin_game' => 'ci', 'Game plain_game' => 'plain'], self::choices($selector->suggestGames($this->user, [], '')));
        // By name, slug or alias, ignoring case
        $this->assertSame(['Game checkin_game' => 'ci'], self::choices($selector->suggestGames($this->user, [], 'CHECKIN')));
        $this->assertSame(['Game checkin_game' => 'ci'], self::choices($selector->suggestGames($this->user, [], 'ci')));
        $this->assertSame([], $selector->suggestGames($this->user, [], 'hsr'));
    }

    public function testProfileSuggestionsAreNarrowedBySectionsGameAndInput(): void
    {
        $this->world->profile($this->user, 1, nickname: 'Nelly', uid: '700000001');
        $this->world->profile($this->user, 2, 'checkin_game', nickname: 'Nelly', uid: '700000002');
        $this->world->profile($this->user, 3, 'plain_game', nickname: 'Plain', uid: '700000003');
        $this->world->profile($this->user, 4, nickname: 'Ruby', uid: '700000004');

        $selector = $this->selector();
        $this->assertSame(
            ['Full Game · Nelly (700000001)' => 'fake:1', 'Full Game · Ruby (700000004)' => 'fake:4'],
            self::choices($selector->suggestProfiles($this->user, ['stamina'], null, '')),
        );
        $this->assertSame(
            ['Full Game · Nelly (700000001)' => 'fake:1', 'Checkin Game · Nelly (700000002)' => 'fake:2', 'Full Game · Ruby (700000004)' => 'fake:4'],
            self::choices($selector->suggestProfiles($this->user, ['check_in'], null, '')),
        );
        // The game option already chosen narrows the profiles
        $this->assertSame(['Checkin Game · Nelly (700000002)' => 'fake:2'], self::choices($selector->suggestProfiles($this->user, ['check_in'], 'ci', '')));
        // Partial input, on the whole label, ignoring case
        $this->assertSame(['Full Game · Nelly (700000001)' => 'fake:1', 'Checkin Game · Nelly (700000002)' => 'fake:2'], self::choices($selector->suggestProfiles($this->user, [], null, 'NEL')));
        $this->assertSame(['Full Game · Ruby (700000004)' => 'fake:4'], self::choices($selector->suggestProfiles($this->user, [], null, '0004')));
        $this->assertSame(['Checkin Game · Nelly (700000002)' => 'fake:2'], self::choices($selector->suggestProfiles($this->user, [], null, 'checkin')));
    }

    public function testUnreadableProfileIsKeptInTheSuggestions(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->profile($this->user, 3, 'plain_game');
        // Its support cannot be told without a context: kept, the answer will say why
        $this->world->fake->profileErrors[3] = new \RuntimeException('Invalid cookie');

        $selector = $this->selector();
        $this->assertSame(['fake:1', 'fake:3'], self::values($selector->suggestProfiles($this->user, ['stamina'], null, '')));
        $this->assertSame(['full', 'plain'], self::values($selector->suggestGames($this->user, ['stamina'], '')));
    }

    public function testAtMostTwentyFiveSuggestions(): void
    {
        for ($id = 1; $id <= 30; $id++) {
            $this->world->profile($this->user, $id);
        }

        $choices = $this->selector()->suggestProfiles($this->user, ['stamina'], null, '');

        $this->assertCount(ChatProfileSelector::MAX_CHOICES, $choices);
        $this->assertSame('fake:25', $choices[24]->value);
    }

    public function testRolesOfTheUserLimitTheSuggestions(): void
    {
        $this->world->otherProfile($this->user, 3);
        $this->assertSame([], $this->selector()->suggestProfiles($this->user, [], null, ''));

        $both = $this->addUser(43, [GamesChatWorld::ROLE_GAMES, GamesChatWorld::ROLE_FAKE, GamesChatWorld::ROLE_OTHER]);
        $this->world->otherProfile($both, 5);
        $this->assertSame(['Other Game · Player5 (8000005)' => 'other:5'], self::choices($this->selector()->suggestProfiles($both, ['stamina'], 'og', '')));
    }
}
