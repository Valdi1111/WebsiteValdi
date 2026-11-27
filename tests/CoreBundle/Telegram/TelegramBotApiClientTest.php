<?php

namespace App\Tests\CoreBundle\Telegram;

use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\Tests\CoreBundle\FakeTelegramApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class TelegramBotApiClientTest extends TestCase
{
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
}
