<?php

namespace App\Tests\CoreBundle\Telegram;

use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\Tests\CoreBundle\FakeTelegramApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\Part\DataPart;

class TelegramBotApiClientTest extends TestCase
{
    /** Bytes no text encoding would keep: a line break, a NUL, an invalid UTF-8 byte */
    private const string PNG = "\x89PNG\r\n\x1a\n\0\xFF--card";

    private FakeTelegramApi $telegram;

    protected function setUp(): void
    {
        $this->telegram = new FakeTelegramApi(new MockClock('2026-11-27 21:00:00'));
    }

    private function client(string $dsn = 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default'): TelegramBotApiClient
    {
        return new TelegramBotApiClient($this->telegram->client(FakeTelegramApi::BOT_API), $dsn);
    }

    public function testCallsAMethodWithItsParametersAndGivesTheResult(): void
    {
        $result = $this->client()->call('getUpdates', ['offset' => 0, 'timeout' => 0, 'allowed_updates' => ['message']], 5.0);

        $this->assertSame([], $result);
        // Falsy values are values: nothing is filtered out
        $this->assertSame([['method' => 'getUpdates', 'params' => ['offset' => 0, 'timeout' => 0, 'allowed_updates' => ['message']]]], $this->telegram->botCalls());
        $this->assertSame('valdi_test_bot', $this->client()->call('getMe')['username']);
    }

    public function testTheTokenComesFromTheDsnOfTheNotifier(): void
    {
        $this->assertTrue($this->client()->isConfigured());
        $this->assertTrue($this->client('telegram://'.FakeTelegramApi::BOT_TOKEN.'@default?channel=123')->isConfigured());
        $this->assertFalse($this->client('')->isConfigured());
        $this->assertFalse($this->client('telegram://onlyuser@default')->isConfigured());
        $this->assertFalse($this->client('not a dsn')->isConfigured());

        $this->expectException(TelegramBotApiException::class);
        $this->client('')->call('getMe');
    }

    public function testRefusalNamesTheMethodAndTheDescriptionNeverTheToken(): void
    {
        $this->telegram->botAnswers['editMessageText'] = [[400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same']]];

        try {
            $this->client()->call('editMessageText', ['chat_id' => 1, 'message_id' => 2, 'text' => 'x']);
            $this->fail('Expected a refusal.');
        } catch (TelegramBotApiException $e) {
            $this->assertSame('editMessageText', $e->method);
            $this->assertSame(400, $e->statusCode);
            $this->assertTrue($e->isMessageNotModified());
            $this->assertStringNotContainsString('bot-secret', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testUnreachableTelegramNeverQuotesTheUrl(): void
    {
        $client = new TelegramBotApiClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Could not resolve host for "https://api.telegram.org/bot'.FakeTelegramApi::BOT_TOKEN.'/getMe"']), FakeTelegramApi::BOT_API), 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default');

        try {
            $client->call('getMe');
            $this->fail('Expected an error.');
        } catch (TelegramBotApiException $e) {
            $this->assertSame('Telegram could not be reached for getMe.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertFalse($e->isMessageNotModified());
        }
    }

    public function testCallMultipartUploadsTheFilesWithTheOtherParametersAsFormFields(): void
    {
        $result = $this->client()->callMultipart('sendPhoto', [
            'chat_id' => (int) FakeTelegramApi::USER_ID,
            'caption' => 'Your <b>pulls</b> & "luck"',
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => []],
            'disable_notification' => false,
            'show_caption_above_media' => true,
            'message_thread_id' => null,
        ], ['photo' => new DataPart(self::PNG, 'pulls-nelly.png', 'image/png')]);

        $this->assertTrue($result);
        $this->assertSame(['POST '.FakeTelegramApi::BOT_API.'bot'.FakeTelegramApi::BOT_TOKEN.'/sendPhoto'], $this->telegram->requestedUrls());
        $this->assertStringStartsWith('Content-Type: multipart/form-data; boundary=', $this->telegram->requests[0]['options']['normalized_headers']['content-type'][0]);
        $this->assertSame([['method' => 'sendPhoto', 'params' => [
            'chat_id' => FakeTelegramApi::USER_ID,
            // As it is: a form field is never escaped
            'caption' => 'Your <b>pulls</b> & "luck"',
            'parse_mode' => 'HTML',
            // Arrays as JSON, booleans as Telegram reads them; null is left out
            'reply_markup' => '{"inline_keyboard":[]}',
            'disable_notification' => 'false',
            'show_caption_above_media' => 'true',
            'photo' => ['filename' => 'pulls-nelly.png', 'contentType' => 'image/png', 'content' => self::PNG],
        ]]], $this->telegram->botCalls());
        $this->assertStringContainsString('Content-Disposition: form-data; name="photo"; filename="pulls-nelly.png"', (string) $this->telegram->requests[0]['options']['body']);
    }

    public function testCallMultipartRefusalIsMappedAsTheOneOfCall(): void
    {
        $this->telegram->botAnswers['sendDocument'] = [[400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: file is too big']]];

        try {
            $this->client()->callMultipart('sendDocument', ['chat_id' => 1], ['document' => new DataPart(self::PNG, 'card.png', 'image/png')]);
            $this->fail('Expected a refusal.');
        } catch (TelegramBotApiException $e) {
            $this->assertSame('Telegram refused sendDocument: HTTP 400, Bad Request: file is too big.', $e->getMessage());
            $this->assertSame('sendDocument', $e->method);
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Bad Request: file is too big', $e->description);
            $this->assertStringNotContainsString('bot-secret', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testCallMultipartAnsweredOkFalseIsARefusalToo(): void
    {
        $this->telegram->botAnswers['sendPhoto'] = [[200, ['ok' => false]]];

        try {
            $this->client()->callMultipart('sendPhoto', ['chat_id' => 1], ['photo' => new DataPart(self::PNG, 'card.png', 'image/png')]);
            $this->fail('Expected a refusal.');
        } catch (TelegramBotApiException $e) {
            $this->assertSame('Telegram refused sendPhoto: HTTP 200.', $e->getMessage());
            $this->assertSame(200, $e->statusCode);
            $this->assertNull($e->description);
        }
    }

    public function testCallMultipartToAnUnreachableTelegramNeverQuotesTheUrl(): void
    {
        $client = new TelegramBotApiClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Could not resolve host for "https://api.telegram.org/bot'.FakeTelegramApi::BOT_TOKEN.'/sendPhoto"']), FakeTelegramApi::BOT_API), 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default');

        try {
            $client->callMultipart('sendPhoto', ['chat_id' => 1], ['photo' => new DataPart(self::PNG, 'card.png', 'image/png')]);
            $this->fail('Expected an error.');
        } catch (TelegramBotApiException $e) {
            $this->assertSame('Telegram could not be reached for sendPhoto.', $e->getMessage());
            $this->assertNull($e->statusCode);
            $this->assertNull($e->getPrevious());
        }
    }

    public function testCallMultipartWithoutTokenCallsNothing(): void
    {
        try {
            $this->client('')->callMultipart('sendPhoto', ['chat_id' => 1], ['photo' => new DataPart(self::PNG, 'card.png', 'image/png')]);
            $this->fail('Expected an error.');
        } catch (TelegramBotApiException $e) {
            $this->assertSame('sendPhoto', $e->method);
            $this->assertSame([], $this->telegram->requests);
        }
    }
}
