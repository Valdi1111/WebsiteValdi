<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\Message\Chat\HandleChatActionMessage;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatDispatchResult;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\CoreBundle\Service\Chat\ChatUserResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChatDispatcherTest extends TestCase
{
    private ChatTestKit $kit;
    private ChatReplyTarget $target;

    protected function setUp(): void
    {
        $this->kit = new ChatTestKit();
        $this->kit->user(1, ['ROLE_GAMES']);
        $this->kit->user(2);
        $this->kit->user(3, disabled: true);
        $this->target = new ChatReplyTarget(ChatPlatform::Discord, ['token' => 'secret-token'], new \DateTimeImmutable('2026-11-27 10:00:00'));
    }

    /**
     * @param array<string, string|int|bool> $arguments
     */
    private function command(string $userId, string $command, ?string $subcommand = null, array $arguments = [], ChatPlatform $platform = ChatPlatform::Discord): ChatDispatchResult
    {
        return $this->kit->dispatcher()->dispatchCommand($platform, $userId, $command, $subcommand, $arguments, 'it', $this->target);
    }

    private function assertRefused(string $expectedText, ChatDispatchResult $result): void
    {
        $this->assertFalse($result->isDeferred());
        $this->assertTrue($result->ephemeral);
        $this->assertSame($expectedText, $result->reply?->text);
        $this->assertSame([], $this->kit->bus->messages);
    }

    public function testUnknownAccountIsToldToLinkIt(): void
    {
        $result = $this->command('d99', 'echo', arguments: ['text' => 'hi']);

        $this->assertRefused('Your Discord account is not linked to a site account yet. Link it from your profile: https://valdi.test/profile', $result);
        $this->assertStringContainsString('Your Telegram account', (string) $this->command('t99', 'help', platform: ChatPlatform::Telegram)->reply?->text);
        // A Telegram id is not a Discord id
        $this->assertStringContainsString('not linked', (string) $this->command('t1', 'help')->reply?->text);
    }

    public function testUserFoundOnEachPlatform(): void
    {
        $this->assertTrue($this->command('d1', 'help')->isDeferred());
        $this->assertTrue($this->command('t1', 'help', platform: ChatPlatform::Telegram)->isDeferred());
        $this->assertTrue($this->command('1', 'help', platform: ChatPlatform::Cli)->isDeferred());
        $this->assertFalse($this->command('user1@example.test', 'help', platform: ChatPlatform::Cli)->isDeferred());
    }

    public function testDisabledUserIsRefused(): void
    {
        $this->assertRefused(ChatReply::error(ChatUserResolver::DISABLED)->text, $this->command('d3', 'help'));
    }

    public function testUnknownCommandIsRefused(): void
    {
        $this->assertRefused(ChatReply::error('Unknown command "/nope". Use /help to see the commands you can use.')->text, $this->command('d1', 'nope'));
        // Only a valid name is repeated
        $this->assertRefused(ChatReply::error('Unknown command. Use /help to see the commands you can use.')->text, $this->command('d1', '@everyone <b>'));
    }

    public function testMissingRoleIsRefused(): void
    {
        $this->assertRefused(ChatReply::error(ChatDispatcher::FORBIDDEN)->text, $this->command('d2', 'remind', 'list'));
        $this->assertRefused(ChatReply::error(ChatDispatcher::FORBIDDEN)->text, $this->command('d1', 'admin'));
    }

    public function testSubcommandMustExist(): void
    {
        $this->assertRefused(ChatReply::error('Choose what /remind should do: stamina, list.')->text, $this->command('d1', 'remind'));
        $this->assertRefused(ChatReply::error('Unknown subcommand "cancel" of /remind. Subcommands: stamina, list.')->text, $this->command('d1', 'remind', 'cancel'));
        $this->assertRefused(ChatReply::error('The command /echo has no subcommands.')->text, $this->command('d1', 'echo', 'list', ['text' => 'hi']));
    }

    public function testRequiredOptionMustBeGiven(): void
    {
        $this->assertRefused(ChatReply::error('The option "text" is required.')->text, $this->command('d1', 'echo'));
        $this->assertRefused(ChatReply::error('The option "text" is required.')->text, $this->command('d1', 'echo', arguments: ['text' => '  ', 'times' => 2]));
        $this->assertRefused(ChatReply::error('The option "value" is required.')->text, $this->command('d1', 'remind', 'stamina', ['game' => 'gi']));
    }

    /**
     * @return iterable<string, array{array<string, string|int|bool>, array<string, string|int|bool>}>
     */
    public static function coercedArguments(): iterable
    {
        yield 'typed values (Discord)' => [['text' => 'hi', 'times' => 3, 'loud' => false, 'tone' => 'calm'], ['text' => 'hi', 'times' => 3, 'loud' => false, 'tone' => 'calm']];
        yield 'strings (typed lines)' => [['text' => 'hi', 'times' => ' +3 ', 'loud' => 'Yes', 'tone' => 'angry'], ['text' => 'hi', 'times' => 3, 'loud' => true, 'tone' => 'angry']];
        yield 'booleans as words' => [['loud' => 'off', 'text' => 'hi'], ['text' => 'hi', 'loud' => false]];
        yield 'numbers as text' => [['text' => 42], ['text' => '42']];
        yield 'undeclared options dropped, empty ones skipped' => [['text' => 'hi', 'volume' => 11, 'friend' => ''], ['text' => 'hi']];
    }

    /**
     * @param array<string, string|int|bool> $arguments
     * @param array<string, string|int|bool> $expected
     */
    #[DataProvider('coercedArguments')]
    public function testArgumentsAreCoercedToTheirTypeInDeclaredOrder(array $arguments, array $expected): void
    {
        $this->assertTrue($this->command('d1', 'echo', arguments: $arguments)->isDeferred());

        $this->assertSame($expected, $this->kit->bus->messages[0]->arguments);
    }

    /**
     * @return iterable<string, array{array<string, string|int|bool>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'not a number' => [['text' => 'hi', 'times' => 'abc'], 'The option "times" must be a whole number.'];
        yield 'decimal number' => [['text' => 'hi', 'times' => '1.5'], 'The option "times" must be a whole number.'];
        yield 'boolean for a number' => [['text' => 'hi', 'times' => true], 'The option "times" must be a whole number.'];
        yield 'not a boolean' => [['text' => 'hi', 'loud' => 'maybe'], 'The option "loud" must be true or false.'];
        yield 'not a choice' => [['text' => 'hi', 'tone' => 'Calm'], 'The option "tone" must be one of: calm, angry.'];
    }

    /**
     * @param array<string, string|int|bool> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentIsRefusedByName(array $arguments, string $message): void
    {
        $this->assertRefused(ChatReply::error($message)->text, $this->command('d1', 'echo', arguments: $arguments));
    }

    public function testAcceptedCommandIsQueuedAndDeferredWithItsVisibility(): void
    {
        $result = $this->command('d1', 'echo', arguments: ['text' => 'hi']);

        $this->assertTrue($result->isDeferred());
        $this->assertFalse($result->ephemeral);
        $this->assertCount(1, $this->kit->bus->messages);
        $message = $this->kit->bus->messages[0];
        $this->assertInstanceOf(ExecuteChatCommandMessage::class, $message);
        $this->assertSame(1, $message->userId);
        $this->assertSame(ChatPlatform::Discord, $message->platform);
        $this->assertSame('d1', $message->platformUserId);
        $this->assertSame('echo', $message->command);
        $this->assertNull($message->subcommand);
        $this->assertSame('it', $message->locale);
        $this->assertSame($this->target, $message->target);
        // Run later, by the worker
        $this->assertSame([], $this->kit->echo->requests);

        $result = $this->command('d1', 'remind', 'stamina', ['value' => '120']);
        $this->assertTrue($result->isDeferred());
        $this->assertTrue($result->ephemeral);
        $this->assertSame('stamina', $this->kit->bus->messages[1]->subcommand);
        $this->assertSame(['value' => 120], $this->kit->bus->messages[1]->arguments);
    }

    public function testQueueFailureIsAnErrorAnswerWithoutTheMessageInTheLogs(): void
    {
        $this->kit->bus->exception = new \RuntimeException('Connection refused for secret-dsn');

        $result = $this->command('d1', 'echo', arguments: ['text' => 'hi']);

        $this->assertFalse($result->isDeferred());
        $this->assertSame(ChatReply::error()->text, $result->reply?->text);
        $this->assertSame(\RuntimeException::class, $this->kit->logger->records[0][2]['error']);
        $this->assertStringNotContainsString('secret', $this->kit->logger->dump());
    }

    public function testSynchronousPathRunsTheCommandAfterTheSameChecks(): void
    {
        $dispatcher = $this->kit->dispatcher();

        $result = $dispatcher->runCommand(ChatPlatform::Cli, '1', 'echo', null, ['text' => 'hi', 'times' => '2'], 'en');
        $this->assertFalse($result->failed);
        $this->assertSame('hi', $result->reply->text);
        $this->assertSame(['text' => 'hi', 'times' => 2], $this->kit->echo->requests[0]->arguments);
        $this->assertSame(ChatPlatform::Cli, $this->kit->echo->requests[0]->platform);
        $this->assertSame([], $this->kit->bus->messages);

        $refused = $dispatcher->runCommand(ChatPlatform::Cli, '2', 'remind', 'list', [], 'en');
        $this->assertTrue($refused->failed);
        $this->assertSame(ChatReply::error(ChatDispatcher::FORBIDDEN)->text, $refused->reply->text);

        $this->assertTrue($dispatcher->runCommand(ChatPlatform::Cli, '1', 'echo', null, [], 'en')->failed);
        $this->assertTrue($dispatcher->runCommand(ChatPlatform::Cli, '3', 'echo', null, ['text' => 'hi'], 'en')->failed);
        $this->assertCount(1, $this->kit->echo->requests);

        // Failures of the command itself come from the executor
        $this->kit->echo->exception = new \RuntimeException('boom');
        $failed = $dispatcher->runCommand(ChatPlatform::Cli, '1', 'echo', null, ['text' => 'hi'], 'en');
        $this->assertTrue($failed->failed);
        $this->assertSame(ChatReply::error()->text, $failed->reply->text);
    }

    public function testAutocompleteGivesTheSuggestionsOfTheCommandCappedAt25(): void
    {
        $this->kit->echo->suggestions = array_map(static fn (int $i): ChatChoice => new ChatChoice('Friend ' . $i, 'f' . $i), range(1, 30));

        $choices = $this->kit->dispatcher()->autocomplete(ChatPlatform::Discord, 'd1', 'echo', null, 'friend', 'Fr', ['text' => 'hi', 'times' => '3', 'loud' => 'maybe', 'friend' => 'Fr', 'volume' => 11]);

        $this->assertCount(25, $choices);
        $this->assertSame('f25', $choices[24]->value);
        // The other options, as far as they are valid
        $this->assertSame([[null, 'friend', 'Fr', ['text' => 'hi', 'times' => 3]]], $this->kit->echo->autocompleteCalls);
    }

    public function testAutocompleteIsEmptyWhenItDoesNotApply(): void
    {
        $this->kit->echo->suggestions = [new ChatChoice('Bob', 'bob')];
        $dispatcher = $this->kit->dispatcher();

        // An option without autocomplete, an unknown option or command, a command without autocomplete
        $this->assertSame([], $dispatcher->autocomplete(ChatPlatform::Discord, 'd1', 'echo', null, 'text', 'h', []));
        $this->assertSame([], $dispatcher->autocomplete(ChatPlatform::Discord, 'd1', 'echo', null, 'volume', 'h', []));
        $this->assertSame([], $dispatcher->autocomplete(ChatPlatform::Discord, 'd1', 'nope', null, 'friend', 'h', []));
        $this->assertSame([], $dispatcher->autocomplete(ChatPlatform::Discord, 'd1', 'remind', 'stamina', 'game', 'g', []));
        $this->assertSame([], $dispatcher->autocomplete(ChatPlatform::Discord, 'd1', 'echo', 'list', 'friend', 'h', []));
        // Not linked, disabled, without the role
        $this->assertSame([], $dispatcher->autocomplete(ChatPlatform::Discord, 'd99', 'echo', null, 'friend', 'h', []));
        $this->assertSame([], $dispatcher->autocomplete(ChatPlatform::Discord, 'd3', 'echo', null, 'friend', 'h', []));
        $this->assertSame([], $dispatcher->autocomplete(ChatPlatform::Discord, 'd2', 'remind', 'stamina', 'game', 'g', []));
        $this->assertSame([], $this->kit->echo->autocompleteCalls);

        $this->assertCount(1, $dispatcher->autocomplete(ChatPlatform::Discord, 'd1', 'echo', null, 'friend', 'h', []));
    }

    public function testAutocompleteFailureIsAnEmptyListLoggedWithoutItsMessage(): void
    {
        $this->kit->echo->autocompleteException = new \RuntimeException('SQL error near secret-value');

        $this->assertSame([], $this->kit->dispatcher()->autocomplete(ChatPlatform::Discord, 'd1', 'echo', null, 'friend', 'h', []));
        $this->assertSame(\RuntimeException::class, $this->kit->logger->records[0][2]['error']);
        $this->assertStringNotContainsString('secret', $this->kit->logger->dump());
    }

    private function action(string $userId, string $data): ChatDispatchResult
    {
        return $this->kit->dispatcher()->dispatchAction(ChatPlatform::Discord, $userId, $data, 'it', $this->target);
    }

    public function testInvalidButtonDataIsRefused(): void
    {
        $refusal = ChatReply::error(ChatDispatcher::INVALID_ACTION)->text;

        $this->assertRefused($refusal, $this->action('d1', ''));
        $this->assertRefused($refusal, $this->action('d1', ':42'));
        $this->assertRefused($refusal, $this->action('d1', 'refresh:' . str_repeat('a', 80)));
        $this->assertRefused($refusal, $this->action('d1', 'snooze:42'));
        $this->assertSame([], $this->kit->refresh->handled);
    }

    public function testButtonOfAnUnknownOrDisabledUserIsRefused(): void
    {
        $this->assertStringContainsString('not linked', (string) $this->action('d99', 'refresh:1')->reply?->text);
        $this->assertRefused(ChatReply::error(ChatUserResolver::DISABLED)->text, $this->action('d3', 'refresh:1'));
    }

    public function testButtonRefusedByItsHandler(): void
    {
        $this->kit->refresh->refusal = 'This is not your profile.';

        $this->assertRefused(ChatReply::error('This is not your profile.')->text, $this->action('d1', 'refresh:42'));
    }

    public function testAuthorizedButtonIsQueued(): void
    {
        $result = $this->action('d1', 'refresh:status:42');

        $this->assertTrue($result->isDeferred());
        $this->assertTrue($result->ephemeral);
        $message = $this->kit->bus->messages[0];
        $this->assertInstanceOf(HandleChatActionMessage::class, $message);
        $this->assertEquals(new ChatActionRef('refresh', ['status', '42']), $message->ref);
        $this->assertSame(1, $message->userId);
        $this->assertSame('d1', $message->platformUserId);
        $this->assertSame('it', $message->locale);
        $this->assertSame($this->target, $message->target);
        $this->assertSame([], $this->kit->refresh->handled);
    }
}
