<?php

namespace App\Tests\CoreBundle\Telegram\Outbound;

use App\CoreBundle\Exception\ChatReplyNotSentException;
use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatImage;
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
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * TelegramReplySender against FakeTelegramApi: the Bot API call of each mode with its exact parameters, and the
 * fallbacks (not modified, HTML Telegram cannot parse, refused edit), with the bot token never in an error or a log. An
 * image goes as a photo or a document by its size, with the text as caption, and as text alone when Telegram refuses it.
 */
class TelegramReplySenderTest extends TestCase
{
    private const string MESSAGE_ID = '321';
    private const string PARSE_ERROR = 'Bad Request: can\'t parse entities: Unsupported start tag "br" at byte offset 42';
    /** Bytes no text encoding would keep: a line break, a NUL, an invalid UTF-8 byte */
    private const string PNG = "\x89PNG\r\n\x1a\n\0\xFF--card";

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

    private static function image(int $width = 2400, int $height = 1880, string $content = self::PNG): ChatImage
    {
        return new ChatImage('pulls-nelly.png', 'image/png', $content, $width, $height, 'Pulls of Nelly');
    }

    private static function imageReply(?ChatImage $image = null, ?string $text = 'Your <pulls>'): ChatReply
    {
        return ChatReply::image($image ?? self::image(), $text);
    }

    /**
     * @return array{filename: string, contentType: string, content: string} the file as FakeTelegramApi::botCalls() gives it
     */
    private static function file(): array
    {
        return ['filename' => 'pulls-nelly.png', 'contentType' => 'image/png', 'content' => self::PNG];
    }

    /**
     * @param array{int, string} $error status and description
     * @return array{int, array<string, mixed>}
     */
    private static function refusal(array $error): array
    {
        return [$error[0], ['ok' => false, 'error_code' => $error[0], 'description' => $error[1]]];
    }

    private function send(ChatReplyMode $mode, ?ChatReplyTarget $target = null, ?TelegramReplySender $sender = null, ?ChatReply $reply = null): ?ChatReplyNotSentException
    {
        try {
            ($sender ?? $this->sender())->send($target ?? TelegramTarget::create(FakeTelegramApi::USER_ID, self::MESSAGE_ID), $reply ?? self::reply(), $mode);

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

    public function testAnImageThatFitsGoesAsAPhotoInANewMessage(): void
    {
        // Even to replace the pressed message: a text message does not become a photo
        $this->assertNull($this->send(ChatReplyMode::Replace, reply: self::imageReply()));

        $this->assertSame([['method' => 'sendPhoto', 'params' => [
            'chat_id' => FakeTelegramApi::USER_ID,
            'caption' => 'Your &lt;pulls&gt;',
            'parse_mode' => 'HTML',
            'photo' => self::file(),
        ]]], $this->telegram->botCalls());
        $this->assertStringStartsWith('Content-Type: multipart/form-data', $this->telegram->requests[0]['options']['normalized_headers']['content-type'][0]);
    }

    public function testATallImageGoesAsADocumentAtFullSize(): void
    {
        $this->assertNull($this->send(ChatReplyMode::FollowUp, reply: self::imageReply(self::image(2400, 6600))));

        $this->assertSame([['method' => 'sendDocument', 'params' => [
            'chat_id' => FakeTelegramApi::USER_ID,
            'caption' => 'Your &lt;pulls&gt;',
            'parse_mode' => 'HTML',
            // A WebP document would become a sticker otherwise
            'disable_content_type_detection' => 'true',
            'document' => self::file(),
        ]]], $this->telegram->botCalls());
    }

    public function testAnImageWithoutTextGoesWithoutCaption(): void
    {
        $this->assertNull($this->send(ChatReplyMode::FollowUp, reply: self::imageReply(text: null)));

        $this->assertSame([['method' => 'sendPhoto', 'params' => ['chat_id' => FakeTelegramApi::USER_ID, 'photo' => self::file()]]], $this->telegram->botCalls());
    }

    #[TestWith([2400, 1880, 'sendPhoto'])]
    #[TestWith([1880, 2400, 'sendPhoto'])]
    #[TestWith([2560, 2560, 'sendPhoto'])]
    #[TestWith([2561, 1000, 'sendDocument'])]
    #[TestWith([1000, 2561, 'sendDocument'])]
    #[TestWith([2400, 6600, 'sendDocument'])]
    #[TestWith([2500, 125, 'sendPhoto'])]
    #[TestWith([2500, 124, 'sendDocument'])]
    public function testTheSizeOfTheImageChoosesPhotoOrDocument(int $width, int $height, string $method): void
    {
        $this->assertNull($this->send(ChatReplyMode::FollowUp, reply: self::imageReply(self::image($width, $height))));

        $this->assertSame([$method], $this->methods());
    }

    public function testAFileOverTenMegabytesGoesAsADocument(): void
    {
        $this->assertNull($this->send(ChatReplyMode::FollowUp, reply: self::imageReply(self::image(1000, 800, str_repeat('x', TelegramReplySender::PHOTO_MAX_BYTES)))));
        $this->assertNull($this->send(ChatReplyMode::FollowUp, reply: self::imageReply(self::image(1000, 800, str_repeat('x', TelegramReplySender::PHOTO_MAX_BYTES + 1)))));

        $this->assertSame(['POST', 'POST'], array_column($this->telegram->requests, 'method'));
        $this->assertSame(
            [FakeTelegramApi::BOT_API.'bot'.FakeTelegramApi::BOT_TOKEN.'/sendPhoto', FakeTelegramApi::BOT_API.'bot'.FakeTelegramApi::BOT_TOKEN.'/sendDocument'],
            array_column($this->telegram->requests, 'url'),
        );
    }

    public function testACaptionTelegramCannotParseIsSentAgainAsPlainText(): void
    {
        $this->telegram->botAnswers['sendDocument'] = [self::refusal([400, self::PARSE_ERROR])];

        $this->assertNull($this->send(ChatReplyMode::FollowUp, reply: self::imageReply(self::image(2400, 6600))));

        $calls = $this->telegram->botCalls();
        $this->assertSame(['sendDocument', 'sendDocument'], $this->methods());
        $this->assertSame([
            'chat_id' => FakeTelegramApi::USER_ID,
            // Shown as it is: not escaped, no parse_mode
            'caption' => 'Your <pulls>',
            'disable_content_type_detection' => 'true',
            'document' => self::file(),
        ], $calls[1]['params']);
        $this->assertSame('warning', $this->logger->cleanLogs()[0][0]);
    }

    public function testARefusedImageSendsTheTextAloneSayingSo(): void
    {
        $this->telegram->botAnswers['sendPhoto'] = [self::refusal([400, 'Bad Request: IMAGE_PROCESS_FAILED'])];

        $this->assertNull($this->send(ChatReplyMode::Replace, reply: self::imageReply()));

        $calls = $this->telegram->botCalls();
        $this->assertSame(['sendPhoto', 'sendMessage'], $this->methods());
        $this->assertSame([
            'chat_id' => (int) FakeTelegramApi::USER_ID,
            'text' => "Your &lt;pulls&gt;\n".ChatReply::IMAGE_NOT_SENT,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => []],
        ], $calls[1]['params']);
        $logs = $this->logger->cleanLogs();
        $this->assertSame(['warning', 400], [$logs[0][0], $logs[0][2]['status']]);
    }

    public function testARefusedImageWithoutTextSaysItAlone(): void
    {
        $this->telegram->botAnswers['sendDocument'] = [self::refusal([413, 'Request Entity Too Large'])];

        $this->assertNull($this->send(ChatReplyMode::FollowUp, reply: self::imageReply(self::image(2400, 6600), null)));

        $this->assertSame(['sendDocument', 'sendMessage'], $this->methods());
        $this->assertSame(ChatReply::IMAGE_NOT_SENT, $this->telegram->botCalls()[1]['params']['text']);
    }

    public function testAPlainCaptionRefusedForAnotherReasonSendsTheTextAlone(): void
    {
        $this->telegram->botAnswers['sendPhoto'] = [self::refusal([400, self::PARSE_ERROR]), self::refusal([400, 'Bad Request: wrong type of the web page content'])];

        $this->assertNull($this->send(ChatReplyMode::FollowUp, reply: self::imageReply()));

        $this->assertSame(['sendPhoto', 'sendPhoto', 'sendMessage'], $this->methods());
        $this->assertStringEndsWith(ChatReply::IMAGE_NOT_SENT, $this->telegram->botCalls()[2]['params']['text']);
    }

    public function testATextFallbackRefusedTooThrowsWithTheStatusOnly(): void
    {
        $this->telegram->botAnswers['sendPhoto'] = [self::refusal([400, 'Bad Request: IMAGE_PROCESS_FAILED'])];
        $this->telegram->botAnswers['sendMessage'] = [self::refusal([403, 'Forbidden: bot was blocked by the user'])];

        $e = $this->send(ChatReplyMode::FollowUp, reply: self::imageReply());

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame('Telegram refused the answer: HTTP 403.', $e->getMessage());
        $this->assertSame(403, $e->statusCode);
        $this->assertSame(['sendPhoto', 'sendMessage'], $this->methods());
        $this->assertNoSecretIn($e);
    }

    public function testAServerErrorOnAnImageThrowsWithoutTheTextFallback(): void
    {
        $this->telegram->botAnswers['sendPhoto'] = [self::refusal([502, 'Bad Gateway'])];

        $e = $this->send(ChatReplyMode::FollowUp, reply: self::imageReply());

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame('Telegram refused the answer: HTTP 502.', $e->getMessage());
        $this->assertSame(502, $e->statusCode);
        $this->assertSame(['sendPhoto'], $this->methods());
        $this->assertNoSecretIn($e);
    }

    public function testARateLimitOnAnImageThrowsWithoutTheTextFallback(): void
    {
        // The text alone would be refused by the same limit: the image is not given up for it
        $this->telegram->botAnswers['sendPhoto'] = [self::refusal([429, 'Too Many Requests: retry after 5'])];

        $e = $this->send(ChatReplyMode::FollowUp, reply: self::imageReply());

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame('Telegram refused the answer: HTTP 429.', $e->getMessage());
        $this->assertSame(429, $e->statusCode);
        $this->assertSame(['sendPhoto'], $this->methods());
    }

    public function testAnUnreachableTelegramForAnImageThrowsWithoutRetry(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            $requests++;

            return new MockResponse('', ['error' => 'Could not resolve host: api.telegram.org']);
        }, FakeTelegramApi::BOT_API);

        $e = $this->send(ChatReplyMode::FollowUp, sender: $this->sender($client), reply: self::imageReply());

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame('Telegram could not be reached for the answer.', $e->getMessage());
        $this->assertNull($e->statusCode);
        $this->assertSame(1, $requests);
        $this->assertNoSecretIn($e);
    }
}
