<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\MessageHandler\Chat\ExecuteChatCommandHandler;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\ValueResolver\DiscordInteractionValueResolver;
use App\Tests\CoreBundle\FakeDiscordApi;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * A slash command from end to end, with the real services: the signed request through the HTTP stack, the real
 * dispatcher (user found by Discord id, command and role checked), the message on the core_chat transport (in memory),
 * the worker handler running /help, and the deferred answer edited on Discord (FakeDiscordApi behind discord.client).
 */
class DiscordChatFlowTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private const string TOKEN = 'aW50ZXJhY3Rpb246Zmxvdw';

    private static string $secretKey;
    private static string $publicKey;

    private FakeDiscordApi $discord;
    private User $user;

    public static function setUpBeforeClass(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        self::$secretKey = sodium_crypto_sign_secretkey($keyPair);
        self::$publicKey = sodium_crypto_sign_publickey($keyPair);
    }

    protected function setUp(): void
    {
        $this->roles['ROLE_USER'] = self::role('ROLE_USER', 'User', null);
        $this->discord = new FakeDiscordApi();
    }

    private function createFlowClient(): KernelBrowser
    {
        $client = $this->createClientWithoutDatabase();
        $container = static::getContainer();

        $container->set(DiscordInteractionValueResolver::class, new DiscordInteractionValueResolver(
            $container->get(SerializerInterface::class),
            bin2hex(self::$publicKey),
        ));
        $container->set('discord.client', $this->discord->client());

        // Linked to the Discord id of FakeDiscordApi; the worker reloads the user by id
        $this->user = $this->createUser(['ROLE_USER'])->setDiscordUserId(FakeDiscordApi::USER_ID);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(fn (mixed $id): ?User => $id === $this->user->getId() ? $this->user : null);
        $users->method('findOneByDiscordUserId')->willReturnCallback(fn (string $id): ?User => $id === $this->user->getDiscordUserId() ? $this->user : null);
        $container->set(UserRepository::class, $users);

        return $client;
    }

    private function postCommand(KernelBrowser $client, string $name, string $discordUserId = FakeDiscordApi::USER_ID): void
    {
        $body = json_encode([
            'type' => 2,
            'id' => '1210000000000000000',
            'application_id' => FakeDiscordApi::APPLICATION_ID,
            'token' => self::TOKEN,
            'locale' => 'it',
            'version' => 1,
            'data' => ['id' => '1220000000000000000', 'name' => $name, 'type' => 1],
            'user' => ['id' => $discordUserId, 'username' => 'nelly'],
        ], \JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $client->request('POST', self::url('domain_name', '/discord/interactions'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE_ED25519' => bin2hex(sodium_crypto_sign_detached($timestamp.$body, self::$secretKey)),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], content: $body);
    }

    private static function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.core_chat');
    }

    public function testHelpIsDeferredQueuedAndAnsweredByTheWorker(): void
    {
        $client = $this->createFlowClient();

        $this->postCommand($client, 'help');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['type' => 5, 'data' => ['flags' => 64]], self::jsonResponse($client));
        $sent = self::transport()->getSent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(ExecuteChatCommandMessage::class, $message);
        $this->assertSame([42, 'help', 'it'], [$message->userId, $message->command, $message->locale]);
        $this->assertSame([], $this->discord->requests, 'Nothing is sent to Discord before the worker');

        // The worker
        static::getContainer()->get(ExecuteChatCommandHandler::class)($message);

        $this->assertSame(['PATCH webhooks/'.FakeDiscordApi::APPLICATION_ID.'/'.self::TOKEN.'/messages/@original'], $this->discord->requestedPaths());
        $edited = $this->discord->jsonBody(0);
        $this->assertSame('', $edited['content']);
        $this->assertSame([], $edited['components']);
        $this->assertSame('Commands', $edited['embeds'][0]['title']);
        // The locale of the interaction: the Italian description
        $this->assertStringContainsString('/help · Elenca i comandi che puoi usare', $edited['embeds'][0]['description']);
    }

    public function testUnlinkedDiscordUserIsAnsweredAtOnceWithTheProfileLink(): void
    {
        $client = $this->createFlowClient();

        $this->postCommand($client, 'help', '99999999999999999');

        $this->assertResponseIsSuccessful();
        $json = self::jsonResponse($client);
        $this->assertSame(4, $json['type']);
        $this->assertSame(64, $json['data']['flags']);
        $this->assertStringContainsString('not linked', $json['data']['content']);
        $this->assertStringContainsString('/profile', $json['data']['content']);
        $this->assertSame([], self::transport()->getSent());
    }

    public function testUnknownCommandIsRefusedAtOnce(): void
    {
        $client = $this->createFlowClient();

        $this->postCommand($client, 'nope');

        $json = self::jsonResponse($client);
        $this->assertSame(4, $json['type']);
        $this->assertStringContainsString('Unknown command "/nope"', $json['data']['content']);
        $this->assertSame([], self::transport()->getSent());
    }
}
