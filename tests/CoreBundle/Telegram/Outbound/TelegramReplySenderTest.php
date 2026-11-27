<?php

namespace App\Tests\CoreBundle\Telegram\Outbound;

use App\CoreBundle\Exception\ChatReplyNotSentException;
use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\Telegram\TelegramReplyRenderer;
use App\CoreBundle\Service\Chat\Telegram\TelegramReplySender;
use App\CoreBundle\Service\Chat\Telegram\TelegramTarget;
use App\CoreBundle\Service\Notification\TelegramHtmlRenderer;
use App\CoreBundle\Service\Notification\TelegramParsedown;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\Tests\CoreBundle\FakeTelegramApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * TelegramReplySender against FakeTelegramApi: the Bot API call of each mode with its exact parameters, and the
 * fallbacks (not modified, HTML Telegram cannot parse, refused edit), with the bot token never in an error or a log.
 */
class TelegramReplySenderTest extends TestCase
{
    private const string MESSAGE_ID = '321';
    private const string PARSE_ERROR = 'Bad Request: can\'t parse entities: Unsupported start tag "br" at byte offset 42';

    private FakeTelegramApi $telegram;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->telegram = new FakeTelegramApi(new MockClock('2026-11-27 21:00:00'));
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    private function renderer(): TelegramReplyRenderer
    {
        return new TelegramReplyRenderer(new TelegramHtmlRenderer(new TelegramParsedown()), new ChatActionCodec());
    }

    private function sender(?HttpClientInterface $client = null): TelegramReplySender
    {
        $botApi = new TelegramBotApiClient($client ?? $this->telegram->client(FakeTelegramApi::BOT_API), 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default');

        return new TelegramReplySender($botApi, $this->renderer(), $this->logger);
    }

    private static function reply(): ChatReply
    {
        return new ChatReply(
            'Your <profiles>',
            [new UniversalEmbed()->setTitle('Stamina')->addField('Trailblaze Power', '180/240', '🔋')],
            [[new ChatAction('Refresh', new ChatActionRef('refresh', ['hsr', '12']), '🔄')]],
            2,
            3,
        );
    }

    /**
     * @param array{int, string} $error status and description
     * @return array{int, array<string, mixed>}
     */
    private static function refusal(array $error): array
    {
        return [$error[0], ['ok' => false, 'error_code' => $error[0], 'description' => $error[1]]];
    }

    private function send(ChatReplyMode $mode, ?ChatReplyTarget $target = null, ?TelegramReplySender $sender = null): ?ChatReplyNotSentException
    {
        try {
            ($sender ?? $this->sender())->send($target ?? TelegramTarget::create(FakeTelegramApi::USER_ID, self::MESSAGE_ID), self::reply(), $mode);

            return null;
        } catch (ChatReplyNotSentException $e) {
            return $e;
        }
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        return array_column($this->telegram->botCalls(), 'method');
    }

    private function assertNoSecretIn(ChatReplyNotSentException $e): void
    {
        $this->assertNull($e->getPrevious());
        foreach ([$e->getMessage(), json_encode($this->logger->cleanLogs(), \JSON_THROW_ON_ERROR)] as $text) {
            $this->assertStringNotContainsString(FakeTelegramApi::BOT_TOKEN, $text);
            $this->assertStringNotContainsString('bot-secret', $text);
            $this->assertStringNotContainsString('api.telegram.org', $text);
        }
    }

    public function testOnlyTelegramIsSupported(): void
    {
        $sender = $this->sender();

        $this->assertTrue($sender->supports(ChatPlatform::Telegram));
        $this->assertFalse($sender->supports(ChatPlatform::Discord));
        $this->assertFalse($sender->supports(ChatPlatform::Cli));
    }

    public function testReplaceEditsThePressedMessage(): void
    {
        $this->assertNull($this->send(ChatReplyMode::Replace));

        $this->assertSame([[
            'method' => 'editMessageText',
            'params' => [
                'chat_id' => (int) FakeTelegramApi::USER_ID,
                'message_id' => (int) self::MESSAGE_ID,
                'text' => "Your &lt;profiles&gt;\n\n<blockquote><b>Stamina</b>\n\n<b>🔋 Trailblaze Power:</b> 180/240</blockquote>\n\nPage 2/3",
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => ['inline_keyboard' => [[['text' => '🔄 Refresh', 'callback_data' => 'refresh:hsr:12']]]],
            ],
        ]], $this->telegram->botCalls());
    }

    public function testReplaceWithoutAMessageSendsANewOne(): void
    {
        $this->assertNull($this->send(ChatReplyMode::Replace, TelegramTarget::create(FakeTelegramApi::USER_ID)));

        $this->assertSame(['sendMessage'], $this->methods());
        $this->assertSame(['chat_id' => (int) FakeTelegramApi::USER_ID] + $this->renderer()->render(self::reply()), $this->telegram->botCalls()[0]['params']);
    }

    public function testFollowUpSendsANewMessageEvenWithAMessageToReplace(): void
    {
        $this->assertNull($this->send(ChatReplyMode::FollowUp));

        $this->assertSame(['sendMessage'], $this->methods());
        $this->assertArrayNotHasKey('message_id', $this->telegram->botCalls()[0]['params']);
    }

    public function testAnEditChangingNothingIsASuccess(): void
    {
        $this->telegram->botAnswers['editMessageText'] = [self::refusal([400, 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same as a current content and reply markup of the message'])];

        $this->assertNull($this->send(ChatReplyMode::Replace));

        $this->assertSame(['editMessageText'], $this->methods());
    }

    public function testHtmlTelegramCannotParseIsSentAgainAsPlainText(): void
    {
        $this->telegram->botAnswers['sendMessage'] = [self::refusal([400, self::PARSE_ERROR])];

        $this->assertNull($this->send(ChatReplyMode::FollowUp));

        $calls = $this->telegram->botCalls();
        $this->assertSame(['sendMessage', 'sendMessage'], $this->methods());
        $this->assertSame([
            'chat_id' => (int) FakeTelegramApi::USER_ID,
            'text' => "Your <profiles>\n\nStamina\n\n🔋 Trailblaze Power: 180/240\n\nPage 2/3",
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => [[['text' => '🔄 Refresh', 'callback_data' => 'refresh:hsr:12']]]],
        ], $calls[1]['params']);
        $this->assertSame('warning', $this->logger->cleanLogs()[0][0]);
    }

    public function testARefusedEditIsSentAsANewMessage(): void
    {
        $this->telegram->botAnswers['editMessageText'] = [self::refusal([400, 'Bad Request: message to edit not found'])];

        $this->assertNull($this->send(ChatReplyMode::Replace));

        $calls = $this->telegram->botCalls();
        $this->assertSame(['editMessageText', 'sendMessage'], $this->methods());
        $edit = $calls[0]['params'];
        unset($edit['message_id']);
        $this->assertSame($edit, $calls[1]['params'], 'The same answer, as a new message');
    }

    public function testAnUnparsableEditThatIsRefusedGoesAsAPlainNewMessage(): void
    {
        $this->telegram->botAnswers['editMessageText'] = [
            self::refusal([400, self::PARSE_ERROR]),
            self::refusal([400, "Bad Request: message can't be edited"]),
        ];

        $this->assertNull($this->send(ChatReplyMode::Replace));

        $calls = $this->telegram->botCalls();
        $this->assertSame(['editMessageText', 'editMessageText', 'sendMessage'], $this->methods());
        $this->assertSame('HTML', $calls[0]['params']['parse_mode']);
        // Plain straight away: the HTML is known to fail
        $this->assertArrayNotHasKey('parse_mode', $calls[1]['params']);
        $this->assertArrayNotHasKey('parse_mode', $calls[2]['params']);
        $this->assertSame($calls[1]['params']['text'], $calls[2]['params']['text']);
    }

    public function testAPlainEditChangingNothingIsASuccessToo(): void
    {
        $this->telegram->botAnswers['editMessageText'] = [
            self::refusal([400, self::PARSE_ERROR]),
            self::refusal([400, 'Bad Request: message is not modified']),
        ];

        $this->assertNull($this->send(ChatReplyMode::Replace));

        $this->assertSame(['editMessageText', 'editMessageText'], $this->methods());
    }

    public function testAnEditFailingForAnotherReasonIsNotSentAgain(): void
    {
        $this->telegram->botAnswers['editMessageText'] = [self::refusal([429, 'Too Many Requests: retry after 5'])];

        $e = $this->send(ChatReplyMode::Replace);

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame(429, $e->statusCode);
        $this->assertSame(['editMessageText'], $this->methods());
    }

    public function testRefusedForGoodThrowsWithTheStatusOnly(): void
    {
        $this->telegram->botAnswers['editMessageText'] = [self::refusal([400, 'Bad Request: message to edit not found'])];
        $this->telegram->botAnswers['sendMessage'] = [self::refusal([403, 'Forbidden: bot was blocked by the user'])];

        $e = $this->send(ChatReplyMode::Replace);

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame('Telegram refused the answer: HTTP 403.', $e->getMessage());
        $this->assertSame(403, $e->statusCode);
        $this->assertSame(['editMessageText', 'sendMessage'], $this->methods());
        $this->assertNoSecretIn($e);
    }

    public function testAnUnparsableAnswerRefusedTwiceThrows(): void
    {
        $this->telegram->botAnswers['sendMessage'] = [self::refusal([400, self::PARSE_ERROR]), self::refusal([400, self::PARSE_ERROR])];

        $e = $this->send(ChatReplyMode::FollowUp);

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame(400, $e->statusCode);
        $this->assertSame(['sendMessage', 'sendMessage'], $this->methods());
    }

    public function testAnUnreachableTelegramIsNotRetried(): void
    {
        // The edit may have gone through: a new message could repeat it
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            $requests++;

            return new MockResponse('', ['error' => 'Could not resolve host: api.telegram.org']);
        }, FakeTelegramApi::BOT_API);

        $e = $this->send(ChatReplyMode::Replace, sender: $this->sender($client));

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame('Telegram could not be reached for the answer.', $e->getMessage());
        $this->assertNull($e->statusCode);
        $this->assertSame(1, $requests);
        $this->assertNoSecretIn($e);
    }

    public function testATargetWithoutChatCallsNothing(): void
    {
        $e = $this->send(ChatReplyMode::FollowUp, new ChatReplyTarget(ChatPlatform::Telegram, [TelegramTarget::MESSAGE_ID => self::MESSAGE_ID]));

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame([], $this->telegram->requests);
    }
}
