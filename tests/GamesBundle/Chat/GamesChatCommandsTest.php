<?php

namespace App\Tests\GamesBundle\Chat;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionStyle;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatExecutionResult;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatActionRegistry;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\CoreBundle\Service\Chat\TextReplyRenderer;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\Tests\GamesBundle\Chat\Fixtures\GamesChatKernelTrait;
use App\Tests\GamesBundle\Chat\Fixtures\GamesChatWorld;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The game chat commands run as chat:run runs them (the real dispatcher: user, role and options checked, then the
 * command in this process), over the profiles of GamesChatWorld read by the real collector and sections.
 */
class GamesChatCommandsTest extends KernelTestCase
{
    use GamesChatKernelTrait;

    private User $user;

    protected function setUp(): void
    {
        $this->bootGamesChat();
        $this->user = $this->addUser(42);
    }

    /**
     * @param array<string, string|int|bool> $arguments
     */
    private function runChat(string $command, array $arguments = [], ?User $user = null): ChatExecutionResult
    {
        return static::getContainer()->get(ChatDispatcher::class)->runCommand(ChatPlatform::Cli, (string) ($user ?? $this->user)->getId(), $command, null, $arguments);
    }

    /**
     * The answer of a command that ran (a refusal of the options included), as the worker would send it.
     *
     * @param array<string, string|int|bool> $arguments
     */
    private function reply(string $command, array $arguments = []): ChatReply
    {
        $result = $this->runChat($command, $arguments);
        $this->assertFalse($result->failed, (string) $result->reply->text);

        return $result->reply;
    }

    /**
     * @return array<string, string> button label => the data it sends
     */
    private static function buttons(ChatReply $reply): array
    {
        $codec = static::getContainer()->get(ChatActionCodec::class);
        $buttons = [];
        foreach ($reply->actionRows as $row) {
            foreach ($row as $action) {
                /** @var ChatAction $action */
                $buttons[$action->label] = $codec->encode($action->ref);
            }
        }

        return $buttons;
    }

    /**
     * @return array<string, string> field name => value, after the header of the profile (player, region, rank)
     */
    private static function fields(UniversalEmbed $embed): array
    {
        $fields = [];
        foreach (array_slice($embed->getFields(), 3) as $field) {
            /** @var EmbedField $field */
            $fields[(string) $field->getName()] = (string) $field->getValue();
        }

        return $fields;
    }

    /**
     * @return list<string|null>
     */
    private static function titles(ChatReply $reply): array
    {
        return array_map(static fn (UniversalEmbed $embed): ?string => $embed->getTitle(), $reply->embeds);
    }

    private function press(string $data): ChatReply
    {
        $ref = static::getContainer()->get(ChatActionCodec::class)->decode($data);

        return static::getContainer()->get(ChatActionRegistry::class)->get($ref->handler)->handle(new ChatUser($this->user, ChatPlatform::Cli, '42'), $ref, 'en');
    }

    private function threeGames(): void
    {
        $this->world->profile($this->user, 1, nickname: 'Nelly', uid: '700000001');
        $this->world->profile($this->user, 2, 'checkin_game');
        $this->world->profile($this->user, 3, 'plain_game');
    }

    public function testStaminaShowsTheSectionOfTheProfilesThatHaveIt(): void
    {
        $this->threeGames();

        $reply = $this->reply('stamina');

        $this->assertNull($reply->text);
        $this->assertSame(['Full Game - Stamina'], self::titles($reply));
        $embed = $reply->embeds[0];
        $this->assertSame('(`700000001`) Nelly', $embed->getFields()[0]->getValue());
        $this->assertSame('120/200', self::fields($embed)['Stamina']);
        $this->assertArrayHasKey('Recovery Time', self::fields($embed));
        // The time of the cached notes, so the age shows
        $this->assertEquals($this->world->full->fetchedAt, $embed->getTimestamp());
        $this->assertSame([1, 1], [$reply->page, $reply->pageCount]);
        $this->assertSame(['Refresh' => 'games:stamina:1:1:::'], self::buttons($reply));
        $this->assertSame([false], $this->world->full->notesCalls);
    }

    public function testDailiesWeekliesAndEndgamesShowTheirSection(): void
    {
        $this->threeGames();

        $dailies = $this->reply('dailies');
        $this->assertSame(['Full Game - Dailies'], self::titles($dailies));
        $this->assertSame(['Daily Tasks' => '2/4'], self::fields($dailies->embeds[0]));
        $this->assertSame(['Refresh' => 'games:dailies:1:1:::'], self::buttons($dailies));

        $weeklies = $this->reply('weeklies');
        $this->assertSame(['Full Game - Weeklies'], self::titles($weeklies));
        $this->assertSame(['Weekly Bosses' => '1/3'], self::fields($weeklies->embeds[0]));

        $endgames = $this->reply('endgames');
        $this->assertSame(['Full Game - Endgames'], self::titles($endgames));
        $embed = $endgames->embeds[0];
        $this->assertSame("Second Mode: no current period\nThird Mode: no current period", $embed->getDescription());
        $this->assertSame('Not played yet', self::fields($embed)['First Mode']);
        $this->assertSame('1 day, 23 hr', self::fields($embed)['Ends in']);
        $this->assertEquals($this->world->full->fetchedAt, $embed->getTimestamp());
        $this->assertSame(['Refresh' => 'games:endgames:1:1:::'], self::buttons($endgames));

        // The notes once per command that needs them, the endgames only for /endgames, all from the caches
        $this->assertSame([false, false], $this->world->full->notesCalls);
        $this->assertSame([['mode' => 'first', 'fresh' => false], ['mode' => 'second', 'fresh' => false], ['mode' => 'third', 'fresh' => false]], $this->world->full->endgameReads);
        $this->assertSame([], $this->world->checkIn->reads);
    }

    public function testStatusShowsTheCompactViewOfTheProfilesWithASection(): void
    {
        $this->threeGames();

        $reply = $this->reply('status');

        $this->assertSame(['Full Game - Status', 'Checkin Game - Status'], self::titles($reply));
        [$full, $checkIn] = $reply->embeds;
        $this->assertSame(['Stamina', 'Dailies', 'Weeklies', 'Check-in'], array_keys(self::fields($full)));
        $this->assertStringStartsWith('120/200 · full in', self::fields($full)['Stamina']);
        $this->assertSame('🔔 Daily Tasks 2/4', self::fields($full)['Dailies']);
        $this->assertSame('Done · day 12', self::fields($full)['Check-in']);
        $this->assertSame('Data from 5 min ago.', $full->getDescription());
        $this->assertSame(['Check-in' => '🔔 Not done today'], self::fields($checkIn));
        $this->assertSame(['Refresh' => 'games:status:1:1:::'], self::buttons($reply));

        // No endgames in the compact view
        $this->assertSame([false], $this->world->full->notesCalls);
        $this->assertSame([false], $this->world->full->checkInReads);
        $this->assertSame([false], $this->world->checkIn->reads);
        $this->assertSame([], $this->world->full->endgameReads);
    }

    public function testUnreadableProfileIsKeptWithTheGenericErrorAndTheAccountProblem(): void
    {
        $this->threeGames();
        $this->world->fake->profileErrors[3] = new \RuntimeException('GET https://api.example.test/notes?cookie=secret');
        $this->world->fake->health = AccountHealth::problem('Log in again on the site.');

        foreach (['stamina', 'status'] as $command) {
            $reply = $this->reply($command);

            $this->assertContains('Plain Game - Status', self::titles($reply), $command);
            $unreadable = $reply->embeds[array_search('Plain Game - Status', self::titles($reply), true)];
            $this->assertSame("⚠️ Log in again on the site.\n⚠️ " . ProfileSummaryPresenter::PROFILE_UNAVAILABLE, $unreadable->getDescription(), $command);
            $this->assertStringNotContainsString('secret', static::getContainer()->get(TextReplyRenderer::class)->render($reply), $command);
        }
        $this->assertSame(['Full Game - Stamina', 'Plain Game - Status'], self::titles($this->reply('stamina')));
    }

    public function testFailedSectionIsGeneric(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->full->dailiesError = new \RuntimeException('GET https://api.example.test/notes?cookie=secret');

        $reply = $this->reply('dailies');

        $this->assertSame(['Full Game - Dailies'], self::titles($reply));
        $this->assertSame('⚠️ ' . ProfileSummaryPresenter::UNAVAILABLE, $reply->embeds[0]->getDescription());
        $this->assertSame([], self::fields($reply->embeds[0]));
        $this->assertStringNotContainsString('secret', static::getContainer()->get(TextReplyRenderer::class)->render($reply));
        // The buttons stay: a Refresh may succeed
        $this->assertSame(['Refresh' => 'games:dailies:1:1:::'], self::buttons($reply));
        // The other sections of the same notes are not affected
        $this->assertSame('120/200', self::fields($this->reply('stamina')->embeds[0])['Stamina']);

        // A failed read of the notes fails every notes section
        $this->world->full->notesError = new \RuntimeException('retcode -100');
        $this->assertSame('⚠️ ' . ProfileSummaryPresenter::UNAVAILABLE, $this->reply('stamina')->embeds[0]->getDescription());

        // With an account problem, the usual cause, the user is told how to fix it
        $this->world->fake->health = AccountHealth::problem('Log in again on the site.');
        $this->assertSame("⚠️ " . ProfileSummaryPresenter::UNAVAILABLE . "\n⚠️ Log in again on the site.", $this->reply('stamina')->embeds[0]->getDescription());
    }

    public function testNothingToShow(): void
    {
        $this->world->profile($this->user, 2, 'checkin_game');
        $this->world->profile($this->user, 3, 'plain_game');

        $this->assertEquals(ChatReply::text('None of your profiles has stamina.'), $this->reply('stamina'));
        $this->assertEquals(ChatReply::text('None of your profiles has dailies.'), $this->reply('dailies'));
        $this->assertEquals(ChatReply::text('None of your profiles has weeklies.'), $this->reply('weeklies'));
        $this->assertEquals(ChatReply::text('None of your profiles has endgames.'), $this->reply('endgames'));
        $this->assertEquals(ChatReply::text('None of your profiles has anything to show.'), $this->reply('status', ['game' => 'plain']));
    }

    public function testOptionsNarrowTheProfilesAndTheButtonsKeepThem(): void
    {
        $this->threeGames();
        $this->world->profile($this->user, 4, nickname: 'Ruby');

        $byGame = $this->reply('stamina', ['game' => 'FULLGAME']);
        $this->assertCount(2, $byGame->embeds);
        $this->assertSame(['Refresh' => 'games:stamina:1:1:full::'], self::buttons($byGame));

        $byProfile = $this->reply('status', ['profile' => 'ruby']);
        $this->assertSame('(`8000004`) Ruby', $byProfile->embeds[0]->getFields()[0]->getValue());
        $this->assertSame(['Refresh' => 'games:status:1:1::fake:4'], self::buttons($byProfile));
    }

    public function testRefusalsOfTheOptions(): void
    {
        $this->assertEquals(ChatReply::error('You have no active game profile yet. Add one on https://' . static::getContainer()->getParameter('games.domain_name') . '/'), $this->reply('stamina'));

        $this->threeGames();
        $this->assertEquals(ChatReply::error('Unknown game "hsr". Your games: full, ci, plain.'), $this->reply('stamina', ['game' => 'hsr']));
        $this->assertEquals(ChatReply::error('No profile "ruby" among yours.'), $this->reply('endgames', ['profile' => 'ruby']));
    }

    public function testGamesRoleIsRequiredAndProvidersFollowTheirRole(): void
    {
        $this->world->profile($this->user, 1);
        $noGames = $this->addUser(43, [GamesChatWorld::ROLE_FAKE]);
        $this->world->profile($noGames, 5);

        foreach (['status', 'stamina', 'dailies', 'weeklies', 'endgames'] as $command) {
            $result = $this->runChat($command, [], $noGames);
            $this->assertTrue($result->failed, $command);
            $this->assertEquals(ChatReply::error(ChatDispatcher::FORBIDDEN), $result->reply, $command);
        }

        // The profiles of a provider without its role are hidden
        $otherOnly = $this->addUser(44, [GamesChatWorld::ROLE_GAMES]);
        $this->world->profile($otherOnly, 6);
        $this->world->otherProfile($otherOnly, 7);
        $this->assertStringStartsWith('⚠️ You have no active game profile yet.', (string) $this->runChat('status', [], $otherOnly)->reply->text);
    }

    /**
     * The suggestions as Discord asks them while the user types: the games and profiles with the sections of the
     * command, narrowed by the options already filled.
     */
    public function testAutocompleteFollowsTheSectionsOfEachCommand(): void
    {
        $this->threeGames();
        $this->world->profile($this->user, 4, nickname: 'Ruby');
        $dispatcher = static::getContainer()->get(ChatDispatcher::class);
        $suggest = static fn (string $command, string $option, array $arguments = [], string $user = '42'): array => array_map(
            static fn (ChatChoice $choice): string|int => $choice->value,
            $dispatcher->autocomplete(ChatPlatform::Cli, $user, $command, null, $option, '', $arguments),
        );

        foreach (['stamina', 'dailies', 'weeklies', 'endgames'] as $command) {
            $this->assertSame(['full'], $suggest($command, 'game'), $command);
            $this->assertSame(['fake:1', 'fake:4'], $suggest($command, 'profile'), $command);
        }
        // The check-in is in the compact view: a game with only the check-in has a status, one without any section not
        $this->assertSame(['full', 'ci'], $suggest('status', 'game'));
        $this->assertSame(['fake:1', 'fake:2', 'fake:4'], $suggest('status', 'profile'));
        $this->assertSame(['fake:2'], $suggest('status', 'profile', ['game' => 'ci']));
        $this->assertSame(['fake:1', 'fake:4'], $suggest('endgames', 'profile', ['game' => 'full']));
        $this->assertSame([], $suggest('endgames', 'profile', ['game' => 'ci']));

        // Not without the role of the commands
        $noGames = $this->addUser(43, [GamesChatWorld::ROLE_FAKE]);
        $this->world->profile($noGames, 5);
        $this->assertSame([], $suggest('stamina', 'profile', [], '43'));
    }

    public function testPagesHoldTenEmbedsWithTheirButtons(): void
    {
        for ($id = 1; $id <= 11; $id++) {
            $this->world->profile($this->user, $id);
        }

        $first = $this->reply('stamina');
        $this->assertCount(10, $first->embeds);
        $this->assertSame([1, 2], [$first->page, $first->pageCount]);
        $this->assertSame(['Next' => 'games:stamina:2:0:::', 'Refresh' => 'games:stamina:1:1:::'], self::buttons($first));
        $this->assertSame([['▶️', ChatActionStyle::Secondary], ['🔄', ChatActionStyle::Primary]], array_map(static fn (ChatAction $action): array => [$action->emoji, $action->style], $first->actionRows[0]));

        $second = $this->press('games:stamina:2:0:::');
        $this->assertCount(1, $second->embeds);
        $this->assertSame('(`80000011`) Player11', $second->embeds[0]->getFields()[0]->getValue());
        $this->assertSame([2, 2], [$second->page, $second->pageCount]);
        $this->assertSame(['Previous' => 'games:stamina:1:0:::', 'Refresh' => 'games:stamina:2:1:::'], self::buttons($second));
        $this->assertSame(['◀️', '🔄'], array_map(static fn (ChatAction $action): ?string => $action->emoji, $second->actionRows[0]));

        // A stale button asking for a page gone is clamped to the last one
        $this->world->fake->profiles = array_slice($this->world->fake->profiles, 0, 10);
        $last = $this->press('games:stamina:2:0:::');
        $this->assertSame([1, 1], [$last->page, $last->pageCount]);
        $this->assertSame(['Refresh' => 'games:stamina:1:1:::'], self::buttons($last));

        $this->assertSame(array_fill(0, 32, false), $this->world->full->notesCalls, 'every page reads every profile, from the caches');
    }

    public function testChatRunPrintsTheAnswerAndItsButtons(): void
    {
        $this->threeGames();
        $tester = new CommandTester(new Application(self::$kernel)->find('chat:run'));

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '42', 'line' => '/stamina full']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Full Game - Stamina', $display);
        $this->assertStringContainsString('🔋 Stamina: 120/200', $display);
        $this->assertStringContainsString('[🔄 Refresh] → games:stamina:1:1:full::', $display);

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '42', 'line' => '/status profile=fake:2', '--render' => 'discord']));
        $json = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('Checkin Game - Status', $json['embeds'][0]['title']);
        $this->assertSame('games:status:1:1::fake:2', $json['components'][0]['components'][0]['custom_id']);
    }
}
