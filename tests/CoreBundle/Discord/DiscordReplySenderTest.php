<?php

namespace App\Tests\CoreBundle\Discord;

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
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\CoreBundle\Service\Chat\Discord\DiscordReplyRenderer;
use App\CoreBundle\Service\Chat\Discord\DiscordReplySender;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use App\Tests\CoreBundle\FakeDiscordApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * DiscordReplySender against FakeDiscordApi: the webhook calls of each mode, an image uploaded as multipart, the
 * text-only retry, and that the interaction token (in the URL) never reaches an exception or a log.
 */
class DiscordReplySenderTest extends TestCase
{
    private const string TOKEN = 'aW50ZXJhY3Rpb246c2VjcmV0LXRva2Vu';
    /** Bytes no text encoding would keep: a line break, a NUL, an invalid UTF-8 byte */
    private const string PNG = "\x89PNG\r\n\x1a\n\0\xFF--card";

    private FakeDiscordApi $discord;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->discord = new FakeDiscordApi();
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    private function sender(): DiscordReplySender
    {
        $renderer = new DiscordReplyRenderer(new DiscordEmbedTransformer(), new DiscordEmbedLimiter(), new ChatActionCodec());

        return new DiscordReplySender($this->discord->client(), $renderer, $this->logger);
    }

    private static function imageReply(?string $text = 'Your pulls'): ChatReply
    {
        return ChatReply::image(new ChatImage('pulls-nelly.png', 'image/png', self::PNG, 2400, 1880, 'Pulls of Nelly'), $text);
    }

    private function contentType(int $index): string
    {
        return $this->discord->requests[$index]['options']['normalized_headers']['content-type'][0] ?? '';
    }

    private static function target(): ChatReplyTarget
    {
        return new ChatReplyTarget(ChatPlatform::Discord, ['application_id' => FakeDiscordApi::APPLICATION_ID, 'token' => self::TOKEN]);
    }

    private static function reply(): ChatReply
    {
        return ChatReply::embeds(
            [new UniversalEmbed()->setTitle('Stamina')->setDescription('180/240')],
            [[new ChatAction('Refresh', new ChatActionRef('refresh', ['hsr', '12']), '🔄')]],
            'Your profiles',
        );
    }

    private function send(ChatReplyMode $mode = ChatReplyMode::Replace, ?ChatReply $reply = null): ?ChatReplyNotSentException
    {
        try {
            $this->sender()->send(self::target(), $reply ?? self::reply(), $mode);

            return null;
        } catch (ChatReplyNotSentException $e) {
            return $e;
        }
    }

    private function assertNoSecretIn(ChatReplyNotSentException $e): void
    {
        $this->assertNull($e->getPrevious(), 'The HttpClient exception quotes the URL.');
        foreach ([$e->getMessage(), json_encode($this->logger->cleanLogs(), \JSON_THROW_ON_ERROR)] as $text) {
            $this->assertStringNotContainsString(self::TOKEN, $text);
            $this->assertStringNotContainsString('webhooks/', $text);
            $this->assertStringNotContainsString(FakeDiscordApi::BASE_URI, $text);
        }
    }

    public function testOnlyDiscordIsSupported(): void
    {
        $sender = $this->sender();

        $this->assertTrue($sender->supports(ChatPlatform::Discord));
        $this->assertFalse($sender->supports(ChatPlatform::Telegram));
        $this->assertFalse($sender->supports(ChatPlatform::Cli));
    }

    public function testReplaceEditsTheOriginalResponseWithTheWholeMessage(): void
    {
        $this->assertNull($this->send(ChatReplyMode::Replace));

        $this->assertSame(['PATCH webhooks/'.FakeDiscordApi::APPLICATION_ID.'/'.self::TOKEN.'/messages/@original'], $this->discord->requestedPaths());
        $body = $this->discord->jsonBody(0);
        $this->assertSame('Your profiles', $body['content']);
        $this->assertSame('Stamina', $body['embeds'][0]['title']);
        $this->assertSame('refresh:hsr:12', $body['components'][0]['components'][0]['custom_id']);
        $this->assertSame(['parse' => []], $body['allowed_mentions']);
        $this->assertArrayNotHasKey('flags', $body);
        // Answers to an interaction are authenticated by its token, never by the bot
        $this->assertNull($this->discord->authorization(0));
    }

    public function testFollowUpPostsAnEphemeralMessage(): void
    {
        $this->assertNull($this->send(ChatReplyMode::FollowUp, ChatReply::text('This is not your reminder.')));

        $this->assertSame(['POST webhooks/'.FakeDiscordApi::APPLICATION_ID.'/'.self::TOKEN], $this->discord->requestedPaths());
        $this->assertSame(
            ['content' => 'This is not your reminder.', 'embeds' => [], 'components' => [], 'allowed_mentions' => ['parse' => []], 'flags' => 64],
            $this->discord->jsonBody(0),
        );
    }

    public function testARefusedMessageIsSentAgainAsTextOnly(): void
    {
        $this->discord->webhookStatuses = [400, 200];

        $this->assertNull($this->send(ChatReplyMode::FollowUp));

        $this->assertCount(2, $this->discord->requests);
        $this->assertSame($this->discord->requestedPaths()[0], $this->discord->requestedPaths()[1]);
        $this->assertSame(
            ['content' => 'Your profiles', 'embeds' => [], 'components' => [], 'allowed_mentions' => ['parse' => []], 'flags' => 64],
            $this->discord->jsonBody(1),
        );
    }

    public function testTheTextOnlyRetryOfAnAnswerWithoutTextIsTheGenericError(): void
    {
        $this->discord->webhookStatuses = [400, 204];

        $this->assertNull($this->send(ChatReplyMode::Replace, ChatReply::embeds([new UniversalEmbed()->setTitle('Stamina')])));

        $this->assertSame(ChatReply::ERROR, $this->discord->jsonBody(1)['content']);
        $this->assertSame([], $this->discord->jsonBody(1)['embeds']);
    }

    public function testRefusedTwiceThrowsWithTheStatusOnly(): void
    {
        $this->discord->webhookStatuses = [400, 404];

        $e = $this->send();

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame(404, $e->statusCode);
        $this->assertStringContainsString('HTTP 404', $e->getMessage());
        $this->assertCount(2, $this->discord->requests);
        $this->assertNoSecretIn($e);
    }

    public function testAServerErrorIsNotRetried(): void
    {
        $this->discord->webhookStatuses = [503];

        $e = $this->send();

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame(503, $e->statusCode);
        $this->assertCount(1, $this->discord->requests);
        $this->assertNoSecretIn($e);
    }

    public function testATransportErrorThrowsWithoutTheUrl(): void
    {
        $this->discord->webhookStatuses = [FakeDiscordApi::TRANSPORT_ERROR];

        $e = $this->send();

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertNull($e->statusCode);
        $this->assertNoSecretIn($e);
    }

    public function testATransportErrorOnTheRetryThrowsWithoutTheUrl(): void
    {
        $this->discord->webhookStatuses = [400, FakeDiscordApi::TRANSPORT_ERROR];

        $e = $this->send();

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertCount(2, $this->discord->requests);
        $this->assertNoSecretIn($e);
    }

    public function testAnImageIsUploadedAsMultipartWithTheMessageAsPayloadJson(): void
    {
        $this->assertNull($this->send(ChatReplyMode::FollowUp, self::imageReply()));

        $this->assertSame(['POST webhooks/'.FakeDiscordApi::APPLICATION_ID.'/'.self::TOKEN], $this->discord->requestedPaths());
        $this->assertStringStartsWith('Content-Type: multipart/form-data; boundary=', $this->contentType(0));
        $body = (string) $this->discord->requests[0]['options']['body'];
        $this->assertStringContainsString('Content-Disposition: form-data; name="payload_json"', $body);
        $this->assertStringContainsString('Content-Disposition: form-data; name="files[0]"; filename="pulls-nelly.png"', $body);
        // The file as it is: not re-encoded
        $this->assertStringContainsString("\r\n\r\n".self::PNG."\r\n", $body);

        $parts = $this->discord->multipartBody(0);
        $this->assertSame(['payload_json', 'files[0]'], array_keys($parts));
        $this->assertSame(['filename' => 'pulls-nelly.png', 'contentType' => 'image/png', 'content' => self::PNG], $parts['files[0]']);
        $this->assertSame([
            'content' => 'Your pulls',
            'embeds' => [],
            'components' => [],
            'allowed_mentions' => ['parse' => []],
            'attachments' => [['id' => 0, 'filename' => 'pulls-nelly.png', 'description' => 'Pulls of Nelly']],
            'flags' => 64,
        ], json_decode($parts['payload_json'], true, flags: \JSON_THROW_ON_ERROR));
        $this->assertNull($this->discord->authorization(0));
    }

    public function testReplaceEditsTheOriginalResponseWithTheImage(): void
    {
        $this->assertNull($this->send(ChatReplyMode::Replace, self::imageReply(null)));

        $this->assertSame(['PATCH webhooks/'.FakeDiscordApi::APPLICATION_ID.'/'.self::TOKEN.'/messages/@original'], $this->discord->requestedPaths());
        $this->assertStringStartsWith('Content-Type: multipart/form-data', $this->contentType(0));
        $message = $this->discord->jsonBody(0);
        // The deferred "thinking..." becomes the image: no text left over, the file listed
        $this->assertSame('', $message['content']);
        $this->assertSame([['id' => 0, 'filename' => 'pulls-nelly.png', 'description' => 'Pulls of Nelly']], $message['attachments']);
        $this->assertArrayNotHasKey('flags', $message);
        $this->assertSame(self::PNG, $this->discord->multipartBody(0)['files[0]']['content']);
    }

    public function testARefusedImageIsSentAgainAsJsonTextSayingSo(): void
    {
        $this->discord->webhookStatuses = [413, 200];

        $this->assertNull($this->send(ChatReplyMode::FollowUp, self::imageReply()));

        $this->assertCount(2, $this->discord->requests);
        $this->assertSame($this->discord->requestedPaths()[0], $this->discord->requestedPaths()[1]);
        $this->assertStringStartsWith('Content-Type: multipart/form-data', $this->contentType(0));
        $this->assertSame('Content-Type: application/json', $this->contentType(1));
        $this->assertSame(
            ['content' => "Your pulls\n".ChatReply::IMAGE_NOT_SENT, 'embeds' => [], 'components' => [], 'allowed_mentions' => ['parse' => []], 'flags' => 64],
            $this->discord->jsonBody(1),
        );
        $this->assertStringNotContainsString(self::PNG, (string) $this->discord->requests[1]['options']['body']);
        $logs = $this->logger->cleanLogs();
        $this->assertSame('warning', $logs[0][0]);
        $this->assertSame(413, $logs[0][2]['status']);
    }

    public function testARefusedImageWithoutTextIsReplacedBySayingSo(): void
    {
        $this->discord->webhookStatuses = [400, 200];

        $this->assertNull($this->send(ChatReplyMode::Replace, self::imageReply(null)));

        $this->assertSame(
            ['content' => ChatReply::IMAGE_NOT_SENT, 'embeds' => [], 'components' => [], 'allowed_mentions' => ['parse' => []]],
            $this->discord->jsonBody(1),
        );
    }

    public function testAServerErrorOnAnImageIsNotRetried(): void
    {
        $this->discord->webhookStatuses = [502];

        $e = $this->send(ChatReplyMode::FollowUp, self::imageReply());

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertSame(502, $e->statusCode);
        $this->assertCount(1, $this->discord->requests);
        $this->assertNoSecretIn($e);
    }

    public function testATransportErrorOnAnImageThrowsWithoutTheUrl(): void
    {
        $this->discord->webhookStatuses = [FakeDiscordApi::TRANSPORT_ERROR];

        $e = $this->send(ChatReplyMode::FollowUp, self::imageReply());

        $this->assertInstanceOf(ChatReplyNotSentException::class, $e);
        $this->assertNull($e->statusCode);
        $this->assertCount(1, $this->discord->requests);
        $this->assertNoSecretIn($e);
    }

    public function testATargetWithoutTokenIsNotSent(): void
    {
        $this->expectException(ChatReplyNotSentException::class);

        try {
            $this->sender()->send(new ChatReplyTarget(ChatPlatform::Discord, ['application_id' => FakeDiscordApi::APPLICATION_ID]), ChatReply::text('Hi'), ChatReplyMode::Replace);
        } finally {
            $this->assertSame([], $this->discord->requests);
        }
    }
}
