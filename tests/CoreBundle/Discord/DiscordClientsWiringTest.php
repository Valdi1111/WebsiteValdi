<?php

namespace App\Tests\CoreBundle\Discord;

use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Service\Chat\ChatReplyDelivery;
use App\CoreBundle\Service\Discord\DiscordBotApiClient;
use App\Tests\CoreBundle\FakeDiscordApi;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Discord services as the container builds them, on the scoped clients of framework.yaml, with the HTTP
 * transport replaced by FakeDiscordApi: the bot token goes only to the bot API, never to the interaction webhooks,
 * which the interaction token in their URL authenticates.
 */
class DiscordClientsWiringTest extends KernelTestCase
{
    private const string BOT_TOKEN = 'wiring-bot-token';

    private FakeDiscordApi $discord;

    /** @var array{server: mixed, env: mixed} */
    private array $previousToken;

    protected function setUp(): void
    {
        // Read at runtime (%env()%) by the container booted below
        $this->previousToken = ['server' => $_SERVER['DISCORD_BOT_TOKEN'] ?? null, 'env' => $_ENV['DISCORD_BOT_TOKEN'] ?? null];
        $_SERVER['DISCORD_BOT_TOKEN'] = $_ENV['DISCORD_BOT_TOKEN'] = self::BOT_TOKEN;

        $this->discord = new FakeDiscordApi();
        static::getContainer()->set('http_client.transport', $this->discord->client());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['server' => '_SERVER', 'env' => '_ENV'] as $key => $global) {
            if ($this->previousToken[$key] === null) {
                unset($GLOBALS[$global]['DISCORD_BOT_TOKEN']);
            } else {
                $GLOBALS[$global]['DISCORD_BOT_TOKEN'] = $this->previousToken[$key];
            }
        }
    }

    public function testTheBotApiSendsTheBotToken(): void
    {
        $botApi = static::getContainer()->get(DiscordBotApiClient::class);

        $this->assertTrue($botApi->isConfigured());
        $this->assertSame(FakeDiscordApi::APPLICATION_ID, $botApi->getApplicationId());
        $this->assertSame('Authorization: Bot '.self::BOT_TOKEN, $this->discord->authorization(0));
    }

    public function testTheWorkerAnswersThroughTheDiscordSenderWithoutTheBotToken(): void
    {
        // DiscordReplySender is only in the tagged senders of the worker's delivery
        $sent = static::getContainer()->get(ChatReplyDelivery::class)->send(
            new ChatReplyTarget(ChatPlatform::Discord, ['application_id' => FakeDiscordApi::APPLICATION_ID, 'token' => 'interaction-token']),
            ChatReply::text('Hello'),
            ChatReplyMode::Replace,
        );

        $this->assertTrue($sent);

        $this->assertSame(['PATCH webhooks/'.FakeDiscordApi::APPLICATION_ID.'/interaction-token/messages/@original'], $this->discord->requestedPaths());
        $this->assertNull($this->discord->authorization(0));
    }
}
