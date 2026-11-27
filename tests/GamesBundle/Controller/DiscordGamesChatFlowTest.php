<?php

namespace App\Tests\GamesBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\Message\Chat\HandleChatActionMessage;
use App\CoreBundle\MessageHandler\Chat\ExecuteChatCommandHandler;
use App\CoreBundle\MessageHandler\Chat\HandleChatActionHandler;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\ValueResolver\DiscordInteractionValueResolver;
use App\GamesBundle\Exception\TooManyStatusRefreshesException;
use App\Tests\CoreBundle\Controller\WithoutDatabaseClientTrait;
use App\Tests\CoreBundle\FakeDiscordApi;
use App\Tests\GamesBundle\Chat\Fixtures\GamesChatWorld;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * A game command and its Refresh button from end to end, with the real services: the signed interactions through the
 * HTTP stack, the real dispatcher (user, role, button checked, rate limiter), the messages on the core_chat transport
 * (in memory), the worker handlers, and the answer edited on Discord (FakeDiscordApi behind discord.client). The
 * profiles are those of GamesChatWorld, read by the real collector and sections.
 */
class DiscordGamesChatFlowTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private const string TOKEN = 'fake-interaction-token-games';

    private static string $secretKey;
    private static string $publicKey;

    private FakeDiscordApi $discord;
    private GamesChatWorld $world;
    private User $user;

    public static function setUpBeforeClass(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        self::$secretKey = sodium_crypto_sign_secretkey($keyPair);
        self::$publicKey = sodium_crypto_sign_publickey($keyPair);
    }

    protected function setUp(): void
    {
        $this->roles[GamesChatWorld::ROLE_GAMES] = self::role(GamesChatWorld::ROLE_GAMES, 'Games', 'Games');
        $this->roles[GamesChatWorld::ROLE_FAKE] = self::role(GamesChatWorld::ROLE_FAKE, 'Fake games', 'Games');
        $this->discord = new FakeDiscordApi();
        $this->world = new GamesChatWorld();
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
        // Before anything builds the collector; also clears the rate limiter
        $this->world->install($container);

        // Linked to the Discord id of FakeDiscordApi; the worker reloads the user by id
        $this->user = $this->createUser([GamesChatWorld::ROLE_GAMES, GamesChatWorld::ROLE_FAKE])->setDiscordUserId(FakeDiscordApi::USER_ID);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(fn (mixed $id): ?User => $id === $this->user->getId() ? $this->user : null);
        $users->method('findOneByDiscordUserId')->willReturnCallback(fn (string $id): ?User => $id === $this->user->getDiscordUserId() ? $this->user : null);
        $container->set(UserRepository::class, $users);

        $this->world->profile($this->user, 1, nickname: 'Nelly', uid: '700000001');
        $this->world->profile($this->user, 2, 'plain_game');

        return $client;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(KernelBrowser $client, int $type, array $data): void
    {
        $body = json_encode([
            'type' => $type,
            'id' => '1210000000000000000',
            'application_id' => FakeDiscordApi::APPLICATION_ID,
            'token' => self::TOKEN,
            'locale' => 'it',
            'version' => 1,
            'data' => $data,
            'user' => ['id' => FakeDiscordApi::USER_ID, 'username' => 'nelly'],
        ], \JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $client->request('POST', self::url('domain_name', '/discord/interactions'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE_ED25519' => bin2hex(sodium_crypto_sign_detached($timestamp.$body, self::$secretKey)),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], content: $body);
    }

    private function pressButton(KernelBrowser $client, string $customId): void
    {
        $this->post($client, 3, ['custom_id' => $customId, 'component_type' => 2]);
    }

    private static function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.core_chat');
    }

    /**
     * The one message the last request queued: the transport is reset between requests, as the services are.
     */
    private function queued(): object
    {
        $sent = self::transport()->getSent();
        $this->assertCount(1, $sent);

        return $sent[0]->getMessage();
    }

    /**
     * @param array<string, mixed> $edited
     */
    private function assertStaminaAnswer(array $edited): void
    {
        $this->assertSame('', $edited['content']);
        $this->assertCount(1, $edited['embeds'], 'the profile of a game without stamina is left out');
        $embed = $edited['embeds'][0];
        $this->assertSame('Full Game - Stamina', $embed['title']);
        $this->assertSame(['Player', "👤\u{00A0}\u{00A0}(`700000001`) Nelly"], [$embed['fields'][0]['name'], $embed['fields'][0]['value']]);
        $this->assertSame(['Stamina', "🔋\u{00A0}\u{00A0}120/200"], [$embed['fields'][3]['name'], $embed['fields'][3]['value']]);
        $this->assertSame([[
            'type' => 1,
            'components' => [['type' => 2, 'style' => 1, 'custom_id' => 'games:stamina:1:1:::', 'label' => 'Refresh', 'emoji' => ['name' => '🔄']]],
        ]], $edited['components']);
    }

    public function testStaminaAndItsRefreshGoThroughTheQueueToTheOriginalMessage(): void
    {
        $client = $this->createFlowClient();
        $original = 'PATCH webhooks/'.FakeDiscordApi::APPLICATION_ID.'/'.self::TOKEN.'/messages/@original';

        $this->post($client, 2, ['id' => '1220000000000000000', 'name' => 'stamina', 'type' => 1]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['type' => 5, 'data' => ['flags' => 64]], self::jsonResponse($client));
        $message = $this->queued();
        $this->assertInstanceOf(ExecuteChatCommandMessage::class, $message);
        $this->assertSame([42, 'stamina', []], [$message->userId, $message->command, $message->arguments]);
        $this->assertSame([], $this->discord->requests, 'Nothing is sent to Discord before the worker');
        $this->assertSame([], $this->world->full->notesCalls, 'Nothing is read before the worker');

        static::getContainer()->get(ExecuteChatCommandHandler::class)($message);

        $this->assertSame([$original], $this->discord->requestedPaths());
        $this->assertStaminaAnswer($this->discord->jsonBody(0));
        $this->assertSame([false], $this->world->full->notesCalls);

        // Refresh: a deferred update, then the same message edited with a fresh read
        $this->pressButton($client, 'games:stamina:1:1:::');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['type' => 6], self::jsonResponse($client));
        $press = $this->queued();
        $this->assertInstanceOf(HandleChatActionMessage::class, $press);
        $this->assertSame(['games', ['stamina', '1', '1', '', '', '']], [$press->ref->handler, $press->ref->args]);

        static::getContainer()->get(HandleChatActionHandler::class)($press);

        $this->assertSame([$original, $original], $this->discord->requestedPaths());
        $this->assertStaminaAnswer($this->discord->jsonBody(1));
        $this->assertSame([false, true], $this->world->full->notesCalls);
    }

    public function testRefreshOverTheLimitIsRefusedAtOnceAndTheMessageStays(): void
    {
        $client = $this->createFlowClient();

        for ($i = 0; $i < 3; $i++) {
            $this->pressButton($client, 'games:stamina:1:1::fake:1');
            $this->assertSame(['type' => 6], self::jsonResponse($client));
            $this->assertInstanceOf(HandleChatActionMessage::class, $this->queued());
        }
        $this->pressButton($client, 'games:stamina:1:1::fake:1');

        $json = self::jsonResponse($client);
        $this->assertSame(4, $json['type']);
        $this->assertSame(64, $json['data']['flags']);
        $this->assertSame('⚠️ '.TooManyStatusRefreshesException::MESSAGE, $json['data']['content']);
        $this->assertSame([], self::transport()->getSent(), 'the refused press is not queued');
        $this->assertSame([], $this->discord->requests);
    }

    public function testButtonOfAnotherUsersProfileIsRefusedAtOnce(): void
    {
        $client = $this->createFlowClient();
        $someoneElse = GamesChatWorld::user(7);
        $this->world->profile($someoneElse, 9);

        $this->pressButton($client, 'games:stamina:1:1::fake:9');

        $json = self::jsonResponse($client);
        $this->assertSame(4, $json['type']);
        $this->assertSame('⚠️ This profile is no longer among yours.', $json['data']['content']);
        $this->assertSame([], self::transport()->getSent());
    }
}
