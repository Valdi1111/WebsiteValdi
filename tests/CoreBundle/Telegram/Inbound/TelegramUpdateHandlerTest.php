<?php

namespace App\Tests\CoreBundle\Telegram\Inbound;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Service\Chat\ChatCommandLineParser;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\CoreBundle\Service\Chat\ChatReplyDelivery;
use App\CoreBundle\Service\Chat\Telegram\TelegramTarget;
use App\CoreBundle\Service\Chat\Telegram\TelegramUpdateHandler;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\Tests\CoreBundle\Chat\ChatTestKit;
use App\Tests\CoreBundle\Chat\Fixtures\RecordingChatReplySender;
use App\Tests\CoreBundle\Chat\Fixtures\RefreshChatAction;
use App\Tests\CoreBundle\FakeTelegramApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * TelegramUpdateHandler with the real chat framework of ChatTestKit (dispatcher, executor, fixture commands and
 * button), the answers recorded by a Telegram sender and the Bot API calls (getMe, answerCallbackQuery) served by
 * FakeTelegramApi. The user of the updates is linked to FakeTelegramApi::USER_ID.
 */
class TelegramUpdateHandlerTest extends TestCase
{
    private const string CALLBACK_ID = '4382000000000000001';
    private const int MESSAGE_ID = 55;

    private MockClock $clock;
    private ChatTestKit $kit;
    private FakeTelegramApi $telegram;
    private RecordingChatReplySender $sender;
    private User $user;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-27 21:00:00');
        $this->kit = new ChatTestKit();
        $this->telegram = new FakeTelegramApi($this->clock);
        $this->sender = new RecordingChatReplySender(ChatPlatform::Telegram);
        $this->user = $this->kit->user(1)->setTelegramChatId(FakeTelegramApi::USER_ID);
    }

    private function handler(?ChatCommandLineParser $parser = null, ?ChatDispatcher $dispatcher = null): TelegramUpdateHandler
    {
        return new TelegramUpdateHandler(
            $parser ?? new ChatCommandLineParser($this->kit->commands()),
            $dispatcher ?? $this->kit->dispatcher(),
            $this->kit->executor(),
            new ChatReplyDelivery([$this->sender], $this->clock, $this->kit->logger),
            new TelegramBotApiClient($this->telegram->client(FakeTelegramApi::BOT_API), 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default'),
            $this->clock,
            $this->kit->logger,
        );
    }

    /**
     * @param array<string, mixed> $from over the user of FakeTelegramApi
     * @param array<string, mixed> $chat over the private chat with that user
     * @return array<string, mixed>
     */
    private function message(?string $text, array $from = [], array $chat = [], int $age = 2): array
    {
        $message = [
            'message_id' => 7,
            'from' => [...['id' => (int) FakeTelegramApi::USER_ID, 'is_bot' => false, 'first_name' => 'Nelly', 'language_code' => 'it'], ...$from],
            'chat' => [...['id' => (int) FakeTelegramApi::USER_ID, 'type' => 'private', 'first_name' => 'Nelly'], ...$chat],
            'date' => $this->clock->now()->getTimestamp() - $age,
        ];
        if ($text !== null) {
            $message['text'] = $text;
        }

        return ['update_id' => 800000001, 'message' => $message];
    }

    /**
     * A press on a button of a message the bot sent $age seconds ago.
     *
     * @param array<string, mixed> $chat    over the private chat with the user
     * @param array<string, mixed> $message over the message of the button
     * @return array<string, mixed>
     */
    private function buttonPress(?string $data, array $chat = [], array $message = [], int $age = 60): array
    {
        $callback = [
            'id' => self::CALLBACK_ID,
            'from' => ['id' => (int) FakeTelegramApi::USER_ID, 'is_bot' => false, 'first_name' => 'Nelly', 'language_code' => 'it'],
            'message' => [...[
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => (int) FakeTelegramApi::BOT_ID, 'is_bot' => true, 'first_name' => 'Valdi test', 'username' => FakeTelegramApi::BOT_USERNAME],
                'chat' => [...['id' => (int) FakeTelegramApi::USER_ID, 'type' => 'private', 'first_name' => 'Nelly'], ...$chat],
                'date' => $this->clock->now()->getTimestamp() - $age,
                'text' => 'Stamina 120/240',
            ], ...$message],
            'chat_instance' => '-5000000000000000001',
        ];
        if ($data !== null) {
            $callback['data'] = $data;
        }

        return ['update_id' => 800000002, 'callback_query' => $callback];
    }

    /**
     * @return list<array{chat: string|null, message: string|null, text: string|null, mode: ChatReplyMode}>
     */
    private function sent(): array
    {
        return array_map(static fn (array $sent): array => [
            'chat' => TelegramTarget::chatId($sent[0]),
            'message' => TelegramTarget::messageId($sent[0]),
            'text' => $sent[1]->text,
            'mode' => $sent[2],
        ], $this->sender->sent);
    }

    /**
     * @return list<string>
     */
    private function botMethods(): array
    {
        return array_column($this->telegram->botCalls(), 'method');
    }

    public function testACommandRunsAsTheLinkedUserAndIsAnsweredWithANewMessage(): void
    {
        $this->handler()->handle($this->message('/echo "hello there" 3'));

        $this->assertCount(1, $this->kit->echo->requests);
        $request = $this->kit->echo->requests[0];
        $this->assertSame(ChatPlatform::Telegram, $request->platform);
        $this->assertSame($this->user, $request->user->user);
        $this->assertSame(FakeTelegramApi::USER_ID, $request->user->platformUserId);
        $this->assertSame('it', $request->locale);
        $this->assertSame(['text' => 'hello there', 'times' => 3], $request->arguments);

        // No message id: a new message in the chat
        $this->assertSame([['chat' => FakeTelegramApi::USER_ID, 'message' => null, 'text' => 'hello there', 'mode' => ChatReplyMode::Replace]], $this->sent());
        $this->assertSame(ChatPlatform::Telegram, $this->sender->sent[0][0]->platform);
        $this->assertSame([], $this->telegram->botCalls(), 'Without "@Bot", the username of the bot is not needed.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function locales(): iterable
    {
        yield 'two letters' => [['language_code' => 'it'], 'it'];
        yield 'with a region' => [['language_code' => 'pt-BR'], 'pt'];
        yield 'upper case' => [['language_code' => 'DE'], 'de'];
        yield 'missing' => [['language_code' => null], 'en'];
        yield 'not a language' => [['language_code' => '1x'], 'en'];
    }

    /**
     * @param array<string, mixed> $from
     */
    #[DataProvider('locales')]
    public function testTheLocaleIsTheLanguageOfTheTelegramApp(array $from, string $locale): void
    {
        $this->handler()->handle($this->message('/echo hi', $from));

        $this->assertSame($locale, $this->kit->echo->requests[0]->locale);
    }

    public function testStartIsAnsweredAsHelp(): void
    {
        $handler = $this->handler();
        $handler->handle($this->message('/help'));
        $handler->handle($this->message('/start'));
        // A deep link "t.me/bot?start=payload": the payload is not an argument of /help
        $handler->handle($this->message('/start welcome-from-site'));

        $this->assertCount(3, $this->sender->sent);
        [[, $help], [, $start], [, $deepLink]] = $this->sender->sent;
        $this->assertStringContainsString('/echo', (string) $help->embeds[0]->getDescription());
        $this->assertEquals($help, $start);
        $this->assertEquals($help, $deepLink);
        $this->assertSame([ChatReplyMode::Replace, ChatReplyMode::Replace, ChatReplyMode::Replace], array_column($this->sent(), 'mode'));
    }

    public function testACommandForAnotherBotIsIgnored(): void
    {
        $this->handler()->handle($this->message('/echo@another_bot hi'));
        // Not even an error about its arguments
        $this->handler()->handle($this->message('/echo@another_bot a b c d e f g'));

        $this->assertSame([], $this->kit->echo->requests);
        $this->assertSame([], $this->sender->sent);
        $this->assertSame(['getMe', 'getMe'], $this->botMethods());
    }

    public function testACommandForThisBotRunsAndItsUsernameIsReadOnce(): void
    {
        $handler = $this->handler();
        $handler->handle($this->message('/echo@'.FakeTelegramApi::BOT_USERNAME.' hi'));
        // Usernames are case-insensitive
        $handler->handle($this->message('/echo@'.strtoupper(FakeTelegramApi::BOT_USERNAME).' again'));

        $this->assertSame(['hi', 'again'], array_column($this->sent(), 'text'));
        $this->assertSame(['getMe'], $this->botMethods());
    }

    public function testWhileGetMeFailsAnySuffixIsAccepted(): void
    {
        $this->telegram->botAnswers['getMe'] = [[502, ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway']]];
        $handler = $this->handler();

        $handler->handle($this->message('/echo@whoever_bot hi'));
        $this->assertSame(['hi'], array_column($this->sent(), 'text'));

        // The failure is not kept: the next command reads the username again, and this one is for another bot
        $handler->handle($this->message('/echo@another_bot hi'));
        $this->assertSame(['getMe', 'getMe'], $this->botMethods());
        $this->assertCount(1, $this->sender->sent);
        $this->assertStringNotContainsString('bot-secret', $this->kit->logger->dump());
    }

    public function testOnlyPrivateChatsAreHandled(): void
    {
        $handler = $this->handler();
        $handler->handle($this->message('/echo hi', chat: ['id' => -1001000000001, 'type' => 'supergroup', 'title' => 'Friends']));
        $handler->handle($this->message('/echo hi', chat: ['id' => -1000000002, 'type' => 'group', 'title' => 'Friends']));
        $handler->handle($this->message('hello', chat: ['id' => -1000000002, 'type' => 'group', 'title' => 'Friends']));

        $this->assertSame([], $this->kit->echo->requests);
        $this->assertSame([], $this->sender->sent);
        $this->assertSame([], $this->telegram->botCalls());
    }

    public function testATextThatIsNotACommandGetsAHint(): void
    {
        $this->handler()->handle($this->message('hello bot'));

        $this->assertSame([['chat' => FakeTelegramApi::USER_ID, 'message' => null, 'text' => TelegramUpdateHandler::NOT_A_COMMAND, 'mode' => ChatReplyMode::Replace]], $this->sent());
    }

    public function testOtherKindsOfMessagesAndUpdatesAreIgnored(): void
    {
        $sticker = $this->message(null);
        $sticker['message']['sticker'] = ['file_id' => 'fake-sticker-file-id', 'emoji' => '👍'];
        $handler = $this->handler();
        $handler->handle($sticker);
        $handler->handle(['update_id' => 800000003, 'edited_message' => $this->message('/echo hi')['message']]);
        $handler->handle(['update_id' => 800000004, 'my_chat_member' => ['chat' => ['id' => 1, 'type' => 'private']]]);
        // Malformed: ignored without an error, as there is no chat to answer
        $handler->handle(['update_id' => 800000005, 'message' => 'not an object']);
        $handler->handle($this->message('/echo hi', chat: ['id' => 'not a number']));
        $handler->handle($this->message('/echo hi', from: ['id' => null]));
        $handler->handle($this->buttonPress(null, message: ['chat' => null]));

        $this->assertSame([], $this->kit->echo->requests);
        $this->assertSame([], $this->sender->sent);
        $this->assertSame([], array_filter($this->kit->logger->records, static fn (array $record): bool => $record[0] === 'error'));
    }

    public function testACommandLineThatCannotBeReadIsAnsweredWithItsError(): void
    {
        $handler = $this->handler();
        $handler->handle($this->message('/echo a b c d e f'));
        $handler->handle($this->message('/'));

        $this->assertSame([
            '⚠️ Too many arguments for /echo: it takes text, times, loud, tone, friend.',
            '⚠️ Type a command, e.g. /help.',
        ], array_column($this->sent(), 'text'));
        $this->assertSame([], $this->kit->echo->requests);
    }

    public function testAnUnlinkedUserIsToldToLinkTheAccount(): void
    {
        $this->handler()->handle($this->message('/echo hi', from: ['id' => 777000111], chat: ['id' => 777000111]));

        $this->assertSame([], $this->kit->echo->requests);
        $this->assertSame([[
            'chat' => '777000111',
            'message' => null,
            'text' => 'Your Telegram account is not linked to a site account yet. Link it from your profile: '.ChatTestKit::DEFAULT_URI.'/profile',
            'mode' => ChatReplyMode::Replace,
        ]], $this->sent());
    }

    public function testARefusedButtonIsOnlyANoticeOnTheButton(): void
    {
        $this->kit->refresh->refusal = 'You can refresh again in 3 minutes.';
        $handler = $this->handler();
        $handler->handle($this->buttonPress('refresh:zzz'));
        // Not a known button: the same kind of notice
        $handler->handle($this->buttonPress('unknown:1'));

        $this->assertSame([
            ['method' => 'answerCallbackQuery', 'params' => ['callback_query_id' => self::CALLBACK_ID, 'text' => 'You can refresh again in 3 minutes.', 'show_alert' => false]],
            ['method' => 'answerCallbackQuery', 'params' => ['callback_query_id' => self::CALLBACK_ID, 'text' => ChatDispatcher::INVALID_ACTION, 'show_alert' => false]],
        ], $this->telegram->botCalls());
        $this->assertSame([], $this->kit->refresh->handled);
        $this->assertSame([], $this->sender->sent, 'The message of the button stays as it is.');
    }

    public function testTheNoticeOfARefusalIsCutToTheLimitOfTelegram(): void
    {
        $this->kit->refresh->refusal = str_repeat('é', 250);
        $this->handler()->handle($this->buttonPress('refresh:zzz'));

        $text = $this->telegram->botCalls()[0]['params']['text'];
        $this->assertSame(TelegramUpdateHandler::MAX_CALLBACK_TEXT_LENGTH, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
    }

    public function testAnUnlinkedUserPressingAButtonGetsTheNoticeToLinkTheAccount(): void
    {
        $this->user->setTelegramChatId('777000111');
        $this->handler()->handle($this->buttonPress('refresh:zzz'));

        $this->assertStringStartsWith('Your Telegram account is not linked', $this->telegram->botCalls()[0]['params']['text']);
        $this->assertSame([], $this->sender->sent);
    }

    public function testAnAuthorizedButtonIsAnsweredFirstThenEditsItsMessage(): void
    {
        $telegram = $this->telegram;
        $this->kit->refresh = $refresh = new class(static fn (): array => $telegram->botCalls()) extends RefreshChatAction {
            /** @var list<array{method: string, params: array<string, mixed>}>|null */
            public ?array $botCallsWhenHandled = null;
            public ?string $locale = null;

            public function __construct(private readonly \Closure $botCalls)
            {
            }

            public function handle(ChatUser $user, ChatActionRef $ref, string $locale): ChatReply
            {
                $this->botCallsWhenHandled = ($this->botCalls)();
                $this->locale = $locale;

                return parent::handle($user, $ref, $locale);
            }
        };

        $this->handler()->handle($this->buttonPress('refresh:zzz:2'));

        // The spinner stops before the work, which can take seconds
        $this->assertSame([['method' => 'answerCallbackQuery', 'params' => ['callback_query_id' => self::CALLBACK_ID]]], $refresh->botCallsWhenHandled);
        $this->assertEquals([new ChatActionRef('refresh', ['zzz', '2'])], $refresh->handled);
        $this->assertSame('it', $refresh->locale);
        $this->assertSame([[
            'chat' => FakeTelegramApi::USER_ID,
            'message' => (string) self::MESSAGE_ID,
            'text' => 'refreshed zzz,2',
            'mode' => ChatReplyMode::Replace,
        ]], $this->sent());
        $this->assertSame(['answerCallbackQuery'], $this->botMethods());
    }

    public function testAButtonWhoseHandlerFailsIsAnsweredApartLeavingTheMessage(): void
    {
        $this->kit->refresh->exception = new \RuntimeException('Refresh failed for bot'.FakeTelegramApi::BOT_TOKEN);
        $this->handler()->handle($this->buttonPress('refresh:zzz'));

        $this->assertSame([['method' => 'answerCallbackQuery', 'params' => ['callback_query_id' => self::CALLBACK_ID]]], $this->telegram->botCalls());
        $this->assertSame([[
            'chat' => FakeTelegramApi::USER_ID,
            'message' => (string) self::MESSAGE_ID,
            'text' => ChatReply::error()->text,
            'mode' => ChatReplyMode::FollowUp,
        ]], $this->sent());
        $this->assertStringNotContainsString('bot-secret', $this->kit->logger->dump());
    }

    public function testAButtonRunsEvenWhenItsQueryCannotBeAnsweredAnymore(): void
    {
        $this->telegram->botAnswers['answerCallbackQuery'] = [[400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: query is too old and response timeout expired or query ID is invalid']]];
        $this->handler()->handle($this->buttonPress('refresh:zzz'));

        $this->assertCount(1, $this->kit->refresh->handled);
        $this->assertSame([ChatReplyMode::Replace], array_column($this->sent(), 'mode'));
        $warnings = array_values(array_filter($this->kit->logger->records, static fn (array $record): bool => $record[0] === 'warning'));
        $this->assertSame([['warning', 'Telegram callback query not answered: {error}', ['error' => 'App\CoreBundle\Exception\TelegramBotApiException', 'status' => 400]]], $warnings);
    }

    public function testAButtonWithoutDataOrOutsideAPrivateChatIsOnlyAnswered(): void
    {
        $handler = $this->handler();
        $handler->handle($this->buttonPress(null));
        $handler->handle($this->buttonPress(''));
        $handler->handle($this->buttonPress('refresh:zzz', chat: ['id' => -1000000002, 'type' => 'group', 'title' => 'Friends']));

        $this->assertSame(array_fill(0, 3, ['method' => 'answerCallbackQuery', 'params' => ['callback_query_id' => self::CALLBACK_ID]]), $this->telegram->botCalls());
        $this->assertSame([], $this->kit->refresh->handled);
        $this->assertSame([], $this->sender->sent);
    }

    public function testPollingSkipsTheUpdatesOlderThanFiveMinutes(): void
    {
        $handler = $this->handler();
        $tooOld = TelegramUpdateHandler::MAX_POLLED_AGE_SECONDS + 1;

        $handler->handle($this->message('/echo backlog', age: $tooOld), polled: true);
        $handler->handle($this->buttonPress('refresh:backlog', age: $tooOld), polled: true);
        $this->assertSame([], $this->kit->echo->requests);
        $this->assertSame([], $this->kit->refresh->handled);
        $this->assertSame([], $this->telegram->botCalls());
        $this->assertSame([], $this->sender->sent);

        // Recent ones are handled, as are a button on a message edited recently (a Refresh) and anything from the webhook
        $handler->handle($this->message('/echo recent', age: TelegramUpdateHandler::MAX_POLLED_AGE_SECONDS), polled: true);
        $handler->handle($this->buttonPress('refresh:edited', message: ['edit_date' => $this->clock->now()->getTimestamp() - 30], age: 3600), polled: true);
        $handler->handle($this->message('/echo webhook', age: 3600));
        $handler->handle($this->buttonPress('refresh:webhook', age: 3600));

        $this->assertSame(['recent', 'webhook'], array_map(static fn ($request) => $request->get('text'), $this->kit->echo->requests));
        $this->assertEquals([new ChatActionRef('refresh', ['edited']), new ChatActionRef('refresh', ['webhook'])], $this->kit->refresh->handled);
    }

    public function testAFailureOfAMessageIsTheGenericErrorAndALogWithoutItsMessage(): void
    {
        $parser = $this->createStub(ChatCommandLineParser::class);
        $parser->method('parse')->willThrowException(new \RuntimeException('Telegram refused bot'.FakeTelegramApi::BOT_TOKEN.'/sendMessage', 7));

        $this->handler($parser)->handle($this->message('/echo hi'));

        $this->assertSame([['chat' => FakeTelegramApi::USER_ID, 'message' => null, 'text' => ChatReply::error()->text, 'mode' => ChatReplyMode::FollowUp]], $this->sent());
        $this->assertSame([['error', 'Telegram update {update_id} not handled: {error}', ['update_id' => 800000001, 'error' => \RuntimeException::class, 'code' => 7]]], $this->kit->logger->records);
        $this->assertStringNotContainsString('bot-secret', $this->kit->logger->dump());
        $this->assertStringNotContainsString('Telegram refused', $this->kit->logger->dump());
    }

    public function testAFailureOfAButtonAnswersItAndSendsTheGenericError(): void
    {
        $dispatcher = $this->createStub(ChatDispatcher::class);
        $dispatcher->method('prepareAction')->willThrowException(new \LogicException('Lost the token bot'.FakeTelegramApi::BOT_TOKEN));

        $this->handler(dispatcher: $dispatcher)->handle($this->buttonPress('refresh:zzz'));

        $this->assertSame([['method' => 'answerCallbackQuery', 'params' => ['callback_query_id' => self::CALLBACK_ID]]], $this->telegram->botCalls());
        // Apart from the message of the button, which stays
        $this->assertSame([['chat' => FakeTelegramApi::USER_ID, 'message' => null, 'text' => ChatReply::error()->text, 'mode' => ChatReplyMode::FollowUp]], $this->sent());
        $this->assertSame([['error', 'Telegram update {update_id} not handled: {error}', ['update_id' => 800000002, 'error' => \LogicException::class, 'code' => 0]]], $this->kit->logger->records);
        $this->assertStringNotContainsString('bot-secret', $this->kit->logger->dump());
    }

    public function testTheTargetOfAnAnswerIsTheTelegramChat(): void
    {
        $this->handler()->handle($this->message('/echo hi'));

        $target = $this->sender->sent[0][0];
        $this->assertInstanceOf(ChatReplyTarget::class, $target);
        $this->assertSame([TelegramTarget::CHAT_ID => FakeTelegramApi::USER_ID], $target->data);
    }
}
