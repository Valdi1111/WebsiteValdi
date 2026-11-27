<?php

namespace App\Tests\CoreBundle\Telegram;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\CoreBundle\Webhook\TelegramRequestParser;
use App\Tests\CoreBundle\Controller\WithoutDatabaseClientTrait;
use App\Tests\CoreBundle\FakeTelegramApi;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A command from end to end, with the real services: the update posted to /webhook/telegram with the secret, queued on
 * core_chat (in memory), consumed as the worker does, the user found by their Telegram id, /help run through the real
 * dispatcher, and the answer sent in HTML by the real renderer and sender (FakeTelegramApi behind the Bot API client).
 */
class TelegramChatFlowTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private const string SECRET = 'test-webhook-secret';
    private const string ENV = 'TELEGRAM_WEBHOOK_SECRET';

    /** @var array{env: mixed, server: mixed} */
    private array $previous;

    private FakeTelegramApi $telegram;
    private User $user;

    protected function setUp(): void
    {
        $this->previous = ['env' => $_ENV[self::ENV] ?? null, 'server' => $_SERVER[self::ENV] ?? null];
        $this->roles['ROLE_USER'] = self::role('ROLE_USER', 'User', null);
        $this->telegram = new FakeTelegramApi(new MockClock());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['env' => &$_ENV, 'server' => &$_SERVER] as $key => &$variables) {
            if ($this->previous[$key] === null) {
                unset($variables[self::ENV]);
            } else {
                $variables[self::ENV] = $this->previous[$key];
            }
        }
    }

    private function createFlowClient(): KernelBrowser
    {
        // Read when the webhook controller is built: before the kernel boots
        $_ENV[self::ENV] = $_SERVER[self::ENV] = self::SECRET;
        $client = $this->createClientWithoutDatabase();
        $container = static::getContainer();

        $container->set(TelegramBotApiClient::class, new TelegramBotApiClient($this->telegram->client(FakeTelegramApi::BOT_API), 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default'));

        $this->user = $this->createUser(['ROLE_USER'])->setTelegramChatId(FakeTelegramApi::USER_ID);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(fn (mixed $id): ?User => $id === $this->user->getId() ? $this->user : null);
        $users->method('findOneByTelegramChatId')->willReturnCallback(fn (string $id): ?User => $id === $this->user->getTelegramChatId() ? $this->user : null);
        $container->set(UserRepository::class, $users);

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(string $text, string $language = 'it'): array
    {
        $chat = ['id' => (int) FakeTelegramApi::USER_ID, 'type' => 'private', 'first_name' => 'Nelly'];

        return [
            'update_id' => 800000020,
            'message' => [
                'message_id' => 42,
                'from' => ['id' => (int) FakeTelegramApi::USER_ID, 'is_bot' => false, 'first_name' => 'Nelly', 'language_code' => $language],
                'chat' => $chat,
                'date' => time(),
                'text' => $text,
                'entities' => [['type' => 'bot_command', 'offset' => 0, 'length' => strlen(explode(' ', $text)[0])]],
            ],
        ];
    }

    private function postAndConsume(KernelBrowser $client, array $update): void
    {
        $client->request('POST', self::url('domain_name', '/webhook/telegram'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', TelegramRequestParser::SECRET_HEADER)) => self::SECRET,
        ], content: json_encode($update, \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.core_chat');
        $sent = $transport->getSent();
        $this->assertCount(1, $sent);
        $this->assertSame([], $this->telegram->botCalls(), 'Nothing is answered during the request');

        // The worker
        static::getContainer()->get('remote_event.messenger.handler')($sent[0]->getMessage());
    }

    public function testHelpIsAnsweredInHtmlInTheLanguageOfTheUser(): void
    {
        $client = $this->createFlowClient();

        $this->postAndConsume($client, self::message('/help'));

        $calls = $this->telegram->botCalls();
        $this->assertSame(['sendMessage'], array_column($calls, 'method'));
        $params = $calls[0]['params'];
        $this->assertSame((int) FakeTelegramApi::USER_ID, $params['chat_id']);
        $this->assertSame('HTML', $params['parse_mode']);
        $this->assertStringContainsString('Commands', $params['text']);
        $this->assertStringContainsString('/help · Elenca i comandi che puoi usare', $params['text']);
        $this->assertSame(['inline_keyboard' => []], $params['reply_markup']);
        $this->assertArrayNotHasKey('message_id', $params);
    }

    public function testStartIsTheHelp(): void
    {
        $client = $this->createFlowClient();

        $this->postAndConsume($client, self::message('/start', 'en'));

        $this->assertStringContainsString('/help · List the commands you can use', $this->telegram->botCalls()[0]['params']['text']);
    }

    public function testAnUnlinkedTelegramUserGetsTheProfileLink(): void
    {
        $client = $this->createFlowClient();
        $this->user->setTelegramChatId('1');

        $this->postAndConsume($client, self::message('/help'));

        $text = $this->telegram->botCalls()[0]['params']['text'];
        $this->assertStringContainsString('not linked', $text);
        $this->assertStringContainsString('/profile', $text);
    }
}
