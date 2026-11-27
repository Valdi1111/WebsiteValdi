<?php

namespace App\Tests\CoreBundle\Telegram\Inbound;

use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\CoreBundle\Webhook\TelegramRequestParser;
use App\Tests\CoreBundle\FakeTelegramApi;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventHandler;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;

/**
 * POST /webhook/telegram through the whole HTTP stack: the route of symfony/webhook, the firewall without security,
 * TelegramRequestParser with the secret of framework.webhook.routing.telegram, and the update queued on core_chat (in
 * memory), then consumed as the worker does.
 *
 * The secret is the real configuration, %env(TELEGRAM_WEBHOOK_SECRET)%, read when the webhook controller is built:
 * each test sets $_ENV / $_SERVER before booting its kernel (createClient() boots a new one, with a new env cache),
 * and tearDown() puts the previous value back. The test environment has none: the webhook is disabled there.
 */
class TelegramWebhookTest extends WebTestCase
{
    private const string SECRET = 'test-webhook-secret';
    private const string ENV = 'TELEGRAM_WEBHOOK_SECRET';
    private const int UPDATE_ID = 800000010;

    /** @var array{env: mixed, server: mixed} */
    private array $previous;

    protected function setUp(): void
    {
        $this->previous = ['env' => $_ENV[self::ENV] ?? null, 'server' => $_SERVER[self::ENV] ?? null];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::restore($_ENV, $this->previous['env']);
        self::restore($_SERVER, $this->previous['server']);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private static function restore(array &$variables, mixed $value): void
    {
        if ($value === null) {
            unset($variables[self::ENV]);
        } else {
            $variables[self::ENV] = $value;
        }
    }

    private function createWebhookClient(string $secret = self::SECRET): KernelBrowser
    {
        $_ENV[self::ENV] = $_SERVER[self::ENV] = $secret;
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /**
     * @param array<string, mixed>|string $body JSON-encoded when an array
     */
    private static function post(KernelBrowser $client, array|string $body, ?string $secret = self::SECRET, string $method = 'POST'): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', TelegramRequestParser::SECRET_HEADER))] = $secret;
        }

        $client->request($method, 'https://'.static::getContainer()->getParameter('domain_name').'/webhook/telegram', server: $server, content: is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * A button pressed in a group: the bot only answers the query, so the whole path runs without a user.
     *
     * @return array<string, mixed>
     */
    private static function update(): array
    {
        return [
            'update_id' => self::UPDATE_ID,
            'callback_query' => [
                'id' => '4382000000000000010',
                'from' => ['id' => (int) FakeTelegramApi::USER_ID, 'is_bot' => false, 'first_name' => 'Nelly'],
                'message' => ['message_id' => 9, 'date' => time(), 'chat' => ['id' => -1000000002, 'type' => 'group', 'title' => 'Friends'], 'text' => 'Stamina'],
                'chat_instance' => '-5000000000000000001',
                'data' => 'refresh:zzz',
            ],
        ];
    }

    private static function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.core_chat');
    }

    private function assertNothingQueued(): void
    {
        $this->assertSame([], self::transport()->getSent());
    }

    private function assertNoCookie(KernelBrowser $client): void
    {
        $this->assertSame([], $client->getResponse()->headers->getCookies(), 'The webhook never touches the session.');
    }

    public function testAnUpdateWithTheSecretIsQueuedOnCoreChatThenHandledByTheWorker(): void
    {
        $client = $this->createWebhookClient();
        $telegram = new FakeTelegramApi(new MockClock());
        static::getContainer()->set(TelegramBotApiClient::class, new TelegramBotApiClient($telegram->client(FakeTelegramApi::BOT_API), 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default'));

        self::post($client, self::update());

        $this->assertResponseStatusCodeSame(202);
        $this->assertNoCookie($client);
        $sent = self::transport()->getSent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(ConsumeRemoteEventMessage::class, $message);
        $this->assertSame('telegram', $message->getType());
        $this->assertSame(TelegramRequestParser::EVENT_NAME, $message->getEvent()->getName());
        $this->assertSame((string) self::UPDATE_ID, $message->getEvent()->getId());
        $this->assertSame(self::update(), $message->getEvent()->getPayload());
        $this->assertSame([], $telegram->botCalls(), 'Nothing is handled during the request.');

        // The worker: the consumer "telegram" hands the update to TelegramUpdateHandler
        /** @var ConsumeRemoteEventHandler $handler */
        $handler = static::getContainer()->get('remote_event.messenger.handler');
        $handler($message);

        $this->assertSame([['method' => 'answerCallbackQuery', 'params' => ['callback_query_id' => '4382000000000000010']]], $telegram->botCalls());
    }

    public function testAWrongOrMissingSecretIsForbidden(): void
    {
        $client = $this->createWebhookClient();

        self::post($client, self::update(), 'another-secret');
        $this->assertResponseStatusCodeSame(403);
        $this->assertNoCookie($client);

        self::post($client, self::update(), null);
        $this->assertResponseStatusCodeSame(403);

        self::post($client, self::update(), '');
        $this->assertResponseStatusCodeSame(403);

        $this->assertNothingQueued();
    }

    public function testWithoutASecretTheWebhookIsDisabled(): void
    {
        $client = $this->createWebhookClient('');

        // Even a request without the header, which would otherwise equal the empty secret
        self::post($client, self::update(), null);
        $this->assertResponseStatusCodeSame(404);
        self::post($client, self::update(), '');
        $this->assertResponseStatusCodeSame(404);
        self::post($client, self::update());
        $this->assertResponseStatusCodeSame(404);

        $this->assertNothingQueued();
    }

    public function testJsonThatIsNotAnUpdateIsABadRequest(): void
    {
        $client = $this->createWebhookClient();

        self::post($client, ['message' => ['text' => '/help']]);
        $this->assertResponseStatusCodeSame(400);
        self::post($client, ['update_id' => '800000010']);
        $this->assertResponseStatusCodeSame(400);

        $this->assertNothingQueued();
    }

    public function testARequestThatIsNotAJsonPostIsRefusedBeforeTheSecret(): void
    {
        $client = $this->createWebhookClient();

        // symfony/webhook answers 406 when the request matcher of the parser (POST, JSON body) refuses it
        self::post($client, 'update_id=1');
        $this->assertResponseStatusCodeSame(406);
        self::post($client, self::update(), method: 'GET');
        $this->assertResponseStatusCodeSame(406);
        // JSON, but not an object
        self::post($client, '"update"');
        $this->assertResponseStatusCodeSame(406);

        $this->assertNothingQueued();
    }

    public function testTelegramWebhookSetPointsTheBotToThisRoute(): void
    {
        $tester = new CommandTester(new Application(static::bootKernel())->find('telegram:webhook:set'));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $url = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['url'];
        $this->assertMatchesRegularExpression('#^https://[^/]+/webhook/telegram$#', $url);
    }
}
