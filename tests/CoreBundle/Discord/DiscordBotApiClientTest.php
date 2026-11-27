<?php

namespace App\Tests\CoreBundle\Discord;

use App\CoreBundle\Exception\DiscordBotApiException;
use App\CoreBundle\Service\Discord\DiscordBotApiClient;
use App\Tests\CoreBundle\FakeDiscordApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * DiscordBotApiClient against FakeDiscordApi. The bot token is added by the scoped client discord.bot.client, so it
 * is not on these requests (DiscordClientsWiringTest checks the wiring).
 */
class DiscordBotApiClientTest extends TestCase
{
    private const string BOT_TOKEN = 'bot-token-secret';

    public function testConfiguredOnlyWithABotToken(): void
    {
        $this->assertTrue(new DiscordBotApiClient(new MockHttpClient(), self::BOT_TOKEN)->isConfigured());
        $this->assertFalse(new DiscordBotApiClient(new MockHttpClient(), '')->isConfigured());
    }

    public function testApplicationIdIsTheBotsOwn(): void
    {
        $discord = new FakeDiscordApi();

        $this->assertSame(FakeDiscordApi::APPLICATION_ID, new DiscordBotApiClient($discord->client(), self::BOT_TOKEN)->getApplicationId());
        $this->assertSame(['GET applications/@me'], $discord->requestedPaths());
    }

    public function testGlobalCommandsAreOverwrittenInOneRequest(): void
    {
        $discord = new FakeDiscordApi();
        $commands = [['name' => 'help', 'type' => 1, 'description' => 'List the commands you can use']];

        $count = new DiscordBotApiClient($discord->client(), self::BOT_TOKEN)->putGlobalCommands(FakeDiscordApi::APPLICATION_ID, $commands);

        $this->assertSame(1, $count);
        $this->assertSame(['PUT applications/'.FakeDiscordApi::APPLICATION_ID.'/commands'], $discord->requestedPaths());
        $this->assertSame($commands, $discord->jsonBody(0));
    }

    public function testAnEmptyListRemovesEveryCommand(): void
    {
        $discord = new FakeDiscordApi();

        $this->assertSame(0, new DiscordBotApiClient($discord->client(), self::BOT_TOKEN)->putGlobalCommands(FakeDiscordApi::APPLICATION_ID, []));
        $this->assertSame([], $discord->jsonBody(0));
    }

    public function testARefusalNamesTheStatusAndDiscordsErrorWithoutTheToken(): void
    {
        $discord = new FakeDiscordApi(commandsStatus: 400);

        try {
            new DiscordBotApiClient($discord->client(), self::BOT_TOKEN)->putGlobalCommands(FakeDiscordApi::APPLICATION_ID, [['name' => 'Bad Name']]);
            $this->fail('Expected a DiscordBotApiException.');
        } catch (DiscordBotApiException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('APPLICATION_COMMAND_INVALID_NAME', $e->getMessage());
            $this->assertStringNotContainsString(self::BOT_TOKEN, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testAnUnauthorizedBotIsAnError(): void
    {
        $this->expectException(DiscordBotApiException::class);
        $this->expectExceptionMessage('Discord refused GET applications/@me: HTTP 401');

        new DiscordBotApiClient(new FakeDiscordApi(applicationStatus: 401)->client(), self::BOT_TOKEN)->getApplicationId();
    }

    public function testATransportErrorIsAnErrorWithoutItsMessage(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host: discord.com']), FakeDiscordApi::BASE_URI);

        try {
            new DiscordBotApiClient($client, self::BOT_TOKEN)->getApplicationId();
            $this->fail('Expected a DiscordBotApiException.');
        } catch (DiscordBotApiException $e) {
            $this->assertSame('Discord could not be reached for GET applications/@me.', $e->getMessage());
            $this->assertNull($e->statusCode);
        }
    }

    public function testAnAnswerWithoutApplicationIdIsAnError(): void
    {
        $this->expectException(DiscordBotApiException::class);

        $client = new MockHttpClient(new MockResponse('{"name":"Valdi"}', ['response_headers' => ['content-type' => 'application/json']]), FakeDiscordApi::BASE_URI);
        new DiscordBotApiClient($client, self::BOT_TOKEN)->getApplicationId();
    }
}
