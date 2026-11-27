<?php

namespace App\Tests\GamesBundle\Chat;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Chat\ChatActionHandlerInterface;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatActionRegistry;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\GamesBundle\Chat\Action\GamesChatAction;
use App\GamesBundle\Chat\ChatProfileSelector;
use App\GamesBundle\Exception\TooManyStatusRefreshesException;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Service\Status\ProfileStatusRefreshLimiter;
use App\Tests\GamesBundle\Chat\Fixtures\GamesChatKernelTrait;
use App\Tests\GamesBundle\Chat\Fixtures\GamesChatWorld;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The buttons of the game chat commands (GamesChatAction, "games:<command>:<page>:<refresh>:<game>:<provider>:<id>"):
 * every press is checked again (data, role, owner, refresh limit) before it is queued, then answered with another page
 * or a fresh read.
 */
class GamesChatActionTest extends KernelTestCase
{
    use GamesChatKernelTrait;

    private User $user;

    protected function setUp(): void
    {
        $this->bootGamesChat();
        $this->user = $this->addUser(42);
    }

    private static function action(): ChatActionHandlerInterface
    {
        $action = static::getContainer()->get(ChatActionRegistry::class)->get(GamesChatAction::NAME);
        self::assertInstanceOf(GamesChatAction::class, $action);

        return $action;
    }

    private static function ref(string $data): ChatActionRef
    {
        return static::getContainer()->get(ChatActionCodec::class)->decode($data);
    }

    private function authorize(string $data, ?User $user = null): ?string
    {
        return self::action()->authorize(new ChatUser($user ?? $this->user, ChatPlatform::Discord, 'd42'), self::ref($data));
    }

    private function handle(string $data): ChatReply
    {
        return self::action()->handle(new ChatUser($this->user, ChatPlatform::Discord, 'd42'), self::ref($data), 'en');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedButtons(): iterable
    {
        yield 'too few parts' => ['games:stamina:1:0::'];
        yield 'too many parts' => ['games:stamina:1:0::::'];
        yield 'no parts' => ['games'];
        yield 'page not a number' => ['games:stamina:x:0:::'];
        yield 'page 0' => ['games:stamina:0:0:::'];
        yield 'negative page' => ['games:stamina:-1:0:::'];
        yield 'refresh flag not 0 or 1' => ['games:stamina:1:2:::'];
        yield 'refresh flag as a word' => ['games:stamina:1:true:::'];
        yield 'unknown command' => ['games:nope:1:0:::'];
        yield 'command of another kind' => ['games:help:1:0:::'];
    }

    #[DataProvider('malformedButtons')]
    public function testMalformedButtonIsRefused(string $data): void
    {
        $this->world->profile($this->user, 1);

        $this->assertSame(ChatDispatcher::INVALID_ACTION, $this->authorize($data));
        $this->assertEquals(ChatReply::error(ChatDispatcher::INVALID_ACTION), $this->handle($data));
        $this->assertSame([], $this->world->full->notesCalls);
    }

    public function testMalformedProfileIsRefusedAsNotFound(): void
    {
        $this->world->profile($this->user, 1);

        foreach (['games:stamina:1:0::fake:x', 'games:stamina:1:0::Fake:1', 'games:stamina:1:0::fake:0', 'games:stamina:1:1:::1'] as $data) {
            $this->assertSame(ChatProfileSelector::NOT_FOUND, $this->authorize($data), $data);
            $this->assertEquals(ChatReply::error(ChatProfileSelector::NOT_FOUND), $this->handle($data), $data);
        }
        $this->assertSame('Unknown game "nope". Your games: full.', $this->authorize('games:stamina:1:0:nope::'));
    }

    public function testGamesRoleIsRequired(): void
    {
        $noGames = $this->addUser(43, [GamesChatWorld::ROLE_FAKE]);
        $this->world->profile($noGames, 5);

        $this->assertSame(ChatDispatcher::FORBIDDEN, $this->authorize('games:stamina:1:0:::', $noGames));
        $this->assertSame(ChatDispatcher::FORBIDDEN, $this->authorize('games:stamina:1:1::fake:5', $noGames));
    }

    public function testProfileOfAnotherUserOrOfAProviderWithoutRoleIsRefused(): void
    {
        $this->world->profile($this->user, 1);
        $someoneElse = $this->addUser(43);
        $this->world->profile($someoneElse, 9);
        $this->world->otherProfile($this->user, 3);

        foreach (['games:stamina:1:0::fake:9', 'games:stamina:1:1::fake:9', 'games:stamina:1:1::other:3'] as $data) {
            $this->assertSame(ChatProfileSelector::NOT_FOUND, $this->authorize($data), $data);
            $this->assertEquals(ChatReply::error(ChatProfileSelector::NOT_FOUND), $this->handle($data), $data);
        }
        $this->assertSame([], $this->world->full->notesCalls);

        // The refused Refresh counted nothing for the owner
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($this->authorize('games:stamina:1:1::fake:9', $someoneElse));
        }
    }

    public function testRefreshIsLimitedPerProfileAndAllOrNothing(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->profile($this->user, 2);

        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($this->authorize('games:stamina:1:1::fake:1'), 'refresh ' . ($i + 1));
        }
        $this->assertSame(TooManyStatusRefreshesException::MESSAGE, $this->authorize('games:stamina:1:1::fake:1'));
        // Any command: the limit is the profile's
        $this->assertSame(TooManyStatusRefreshesException::MESSAGE, $this->authorize('games:endgames:1:1::fake:1'));

        // Both profiles: refused for the first, and nothing taken from the second
        $this->assertSame(TooManyStatusRefreshesException::MESSAGE, $this->authorize('games:status:1:1:::'));
        $this->assertSame(TooManyStatusRefreshesException::MESSAGE, $this->authorize('games:status:1:1:full::'));
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($this->authorize('games:dailies:1:1::fake:2'), 'refresh ' . ($i + 1) . ' of the second');
        }
        $this->assertSame(TooManyStatusRefreshesException::MESSAGE, $this->authorize('games:dailies:1:1::fake:2'));

        // Pages read the caches: never limited
        $this->assertNull($this->authorize('games:stamina:1:0::fake:1'));
        $this->assertNull($this->authorize('games:stamina:1:0:::'));
    }

    public function testRefreshCountsOnlyTheProfilesTheCommandMayShow(): void
    {
        $this->world->profile($this->user, 1);
        // No section at all: never shown by the section commands nor by /status
        $this->world->profile($this->user, 2, 'plain_game');
        $limiter = static::getContainer()->get(ProfileStatusRefreshLimiter::class);
        for ($i = 0; $i < 3; $i++) {
            $limiter->consume($this->user, new GameProfileRef('fake', 2));
        }

        $this->assertNull($this->authorize('games:stamina:1:1:::'));
        $this->assertNull($this->authorize('games:status:1:1:::'));
    }

    public function testPageIsRenderedFromTheCaches(): void
    {
        for ($id = 1; $id <= 12; $id++) {
            $this->world->profile($this->user, $id);
        }

        $reply = $this->handle('games:dailies:2:0:full::');

        $this->assertSame([2, 2], [$reply->page, $reply->pageCount]);
        $this->assertSame(['Full Game - Dailies', 'Full Game - Dailies'], array_map(static fn (UniversalEmbed $embed): ?string => $embed->getTitle(), $reply->embeds));
        $this->assertSame('(`80000011`) Player11', $reply->embeds[0]->getFields()[0]->getValue());
        $this->assertSame(['games:dailies:1:0:full::', 'games:dailies:2:1:full::'], $this->buttonData($reply));
        $this->assertSame(array_fill(0, 12, false), $this->world->full->notesCalls);
    }

    public function testRefreshReadsAgainWithoutTheCaches(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->profile($this->user, 2, 'checkin_game');

        $stamina = $this->handle('games:stamina:1:1::fake:1');
        $this->assertSame('Full Game - Stamina', $stamina->embeds[0]->getTitle());
        $this->assertSame(['games:stamina:1:1::fake:1'], $this->buttonData($stamina));
        $this->assertSame([true], $this->world->full->notesCalls);

        $this->handle('games:endgames:1:1:::');
        $this->assertSame([true, true, true], array_column($this->world->full->endgameReads, 'fresh'));

        $this->handle('games:status:1:1:::');
        $this->assertSame([true, true], $this->world->full->notesCalls);
        $this->assertSame([true], $this->world->full->checkInReads);
        $this->assertSame([true], $this->world->checkIn->reads);
    }

    public function testProfileGoneSinceTheMessageIsRefusedByTheWorkerToo(): void
    {
        $this->world->profile($this->user, 1);
        $this->world->profile($this->user, 2);
        $this->assertNull($this->authorize('games:stamina:1:1::fake:1'));

        array_shift($this->world->fake->profiles);

        $this->assertEquals(ChatReply::error(ChatProfileSelector::NOT_FOUND), $this->handle('games:stamina:1:1::fake:1'));
        $this->assertCount(1, $this->handle('games:stamina:1:0:::')->embeds);
        $this->world->fake->profiles = [];
        $this->assertStringStartsWith('⚠️ You have no active game profile yet.', (string) $this->handle('games:stamina:1:0:::')->text);
    }

    /**
     * @return list<string>
     */
    private function buttonData(ChatReply $reply): array
    {
        $codec = static::getContainer()->get(ChatActionCodec::class);
        $data = [];
        foreach ($reply->actionRows as $row) {
            foreach ($row as $action) {
                $data[] = $codec->encode($action->ref);
            }
        }

        return $data;
    }
}
