<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatDispatcherInterface;
use App\CoreBundle\Model\Chat\ChatDispatchResult;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\ValueResolver\DiscordInteractionValueResolver;
use App\Tests\CoreBundle\Discord\RecordingChatDispatcher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * POST /discord/interactions through the whole HTTP stack (firewall without security, signature, serializer,
 * controller, renderer), signed with a key pair made for the test. The verifier is rebuilt with that public key (the
 * test environment has none: the endpoint is disabled there, as a 200 below could not happen otherwise), and the
 * dispatcher is RecordingChatDispatcher, so no user, command or queue is involved.
 */
class DiscordInteractionsTest extends WebTestCase
{
    private const string APPLICATION_ID = '1180000000000000001';
    private const string TOKEN = 'aW50ZXJhY3Rpb246dGVzdA';
    private const string USER_ID = '80351110224678912';

    private static string $secretKey;
    private static string $publicKey;

    private RecordingChatDispatcher $dispatcher;

    public static function setUpBeforeClass(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        self::$secretKey = sodium_crypto_sign_secretkey($keyPair);
        self::$publicKey = sodium_crypto_sign_publickey($keyPair);
    }

    private function createInteractionsClient(bool $configured = true): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();

        if ($configured) {
            $container->set(DiscordInteractionValueResolver::class, new DiscordInteractionValueResolver(
                $container->get(SerializerInterface::class),
                bin2hex(self::$publicKey),
            ));
        }
        $this->dispatcher = new RecordingChatDispatcher();
        $container->set(ChatDispatcherInterface::class, $this->dispatcher);

        return $client;
    }

    /**
     * @param array<string, mixed> $interaction
     */
    private static function post(KernelBrowser $client, array $interaction, bool $signed = true): void
    {
        $body = json_encode($interaction, \JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signed) {
            $server['HTTP_X_SIGNATURE_ED25519'] = bin2hex(sodium_crypto_sign_detached($timestamp.$body, self::$secretKey));
            $server['HTTP_X_SIGNATURE_TIMESTAMP'] = $timestamp;
        }

        $client->request('POST', 'https://'.static::getContainer()->getParameter('domain_name').'/discord/interactions', server: $server, content: $body);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function interaction(int $type, array $data = [], bool $inServer = true, ?string $locale = 'it'): array
    {
        $interaction = [
            'type' => $type,
            'id' => '1210000000000000000',
            'application_id' => self::APPLICATION_ID,
            'token' => self::TOKEN,
            'locale' => $locale,
            'version' => 1,
            'data' => $data,
        ];

        return $inServer
            ? $interaction + ['guild_id' => '1000000000000000003', 'member' => ['user' => ['id' => self::USER_ID, 'username' => 'nelly'], 'roles' => []]]
            : $interaction + ['user' => ['id' => self::USER_ID, 'username' => 'nelly']];
    }

    /**
     * @return array<mixed>
     */
    private static function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function assertNoCookie(KernelBrowser $client): void
    {
        $this->assertSame([], $client->getResponse()->headers->getCookies(), 'The interactions never touch the session.');
        $this->assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    private function assertTarget(ChatReplyTarget $target): void
    {
        $this->assertSame(ChatPlatform::Discord, $target->platform);
        $this->assertSame(['application_id' => self::APPLICATION_ID, 'token' => self::TOKEN], $target->data);
        $this->assertEqualsWithDelta(time(), $target->receivedAt->getTimestamp(), 5);
    }

    public function testPingIsAnsweredWithPong(): void
    {
        $client = $this->createInteractionsClient();

        self::post($client, ['type' => 1, 'id' => '1', 'application_id' => self::APPLICATION_ID, 'token' => self::TOKEN, 'version' => 1]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $this->assertSame(['type' => 1], self::json($client));
        $this->assertNoCookie($client);
    }

    public function testCommandWithASubcommandIsDeferredForTheUserAloneWithItsArgumentsFlattened(): void
    {
        $client = $this->createInteractionsClient();

        self::post($client, self::interaction(2, [
            'id' => '1190000000000000000',
            'name' => 'remind',
            'type' => 1,
            'options' => [[
                'name' => 'stamina',
                'type' => 1,
                'options' => [
                    ['name' => 'game', 'type' => 3, 'value' => 'hsr'],
                    ['name' => 'threshold', 'type' => 4, 'value' => 200],
                    ['name' => 'silent', 'type' => 5, 'value' => false],
                ],
            ]],
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSame(['type' => 5, 'data' => ['flags' => 64]], self::json($client));
        $this->assertCount(1, $this->dispatcher->commands);
        $call = $this->dispatcher->commands[0];
        $this->assertSame(
            [ChatPlatform::Discord, self::USER_ID, 'remind', 'stamina', ['game' => 'hsr', 'threshold' => 200, 'silent' => false], 'it'],
            [$call['platform'], $call['platformUserId'], $call['command'], $call['subcommand'], $call['arguments'], $call['locale']],
        );
        $this->assertTarget($call['target']);
        $this->assertNoCookie($client);
    }

    public function testPublicCommandFromADirectMessageIsDeferredWithoutFlagsAndInTheLanguageOfTheClient(): void
    {
        $client = $this->createInteractionsClient();
        $this->dispatcher->commandResult = ChatDispatchResult::deferred(false);

        self::post($client, self::interaction(2, ['name' => 'stamina', 'type' => 1, 'options' => [['name' => 'game', 'type' => 3, 'value' => 'zzz']]], inServer: false, locale: 'en-US'));

        $this->assertSame(['type' => 5], self::json($client));
        $call = $this->dispatcher->commands[0];
        $this->assertSame([self::USER_ID, 'stamina', null, ['game' => 'zzz'], 'en'], [$call['platformUserId'], $call['command'], $call['subcommand'], $call['arguments'], $call['locale']]);
    }

    public function testCommandWithoutLocaleIsInEnglish(): void
    {
        $client = $this->createInteractionsClient();

        self::post($client, self::interaction(2, ['name' => 'help', 'type' => 1], locale: null));

        $this->assertSame('en', $this->dispatcher->commands[0]['locale']);
        $this->assertSame([], $this->dispatcher->commands[0]['arguments']);
    }

    public function testImmediateAnswerOfACommandIsAMessageForTheUserAlone(): void
    {
        $client = $this->createInteractionsClient();
        $this->dispatcher->commandResult = ChatDispatchResult::immediate(ChatReply::text('Link your Discord account first: https://valdi.test/profile'));

        self::post($client, self::interaction(2, ['name' => 'stamina', 'type' => 1]));

        $this->assertResponseIsSuccessful();
        $this->assertSame([
            'type' => 4,
            'data' => [
                'content' => 'Link your Discord account first: https://valdi.test/profile',
                'embeds' => [],
                'components' => [],
                'allowed_mentions' => ['parse' => []],
                'flags' => 64,
            ],
        ], self::json($client));
    }

    public function testSubcommandGroupIsAnsweredWithAnErrorWithoutDispatching(): void
    {
        $client = $this->createInteractionsClient();

        self::post($client, self::interaction(2, ['name' => 'remind', 'type' => 1, 'options' => [
            ['name' => 'stamina', 'type' => 2, 'options' => [['name' => 'set', 'type' => 1, 'options' => []]]],
        ]]));

        $response = self::json($client);
        $this->assertSame(4, $response['type']);
        $this->assertSame(64, $response['data']['flags']);
        $this->assertSame(ChatReply::error()->text, $response['data']['content']);
        $this->assertSame([], $this->dispatcher->commands);
    }

    public function testPressedButtonIsADeferredUpdate(): void
    {
        $client = $this->createInteractionsClient();

        self::post($client, self::interaction(3, ['custom_id' => 'refresh:hsr:12', 'component_type' => 2], locale: 'it'));

        $this->assertResponseIsSuccessful();
        $this->assertSame(['type' => 6], self::json($client));
        $this->assertCount(1, $this->dispatcher->actions);
        $call = $this->dispatcher->actions[0];
        $this->assertSame([ChatPlatform::Discord, self::USER_ID, 'refresh:hsr:12', 'it'], [$call['platform'], $call['platformUserId'], $call['actionData'], $call['locale']]);
        $this->assertTarget($call['target']);
        $this->assertNoCookie($client);
    }

    public function testRefusedButtonGetsANewMessageForThePresserAlone(): void
    {
        $client = $this->createInteractionsClient();
        $this->dispatcher->actionResult = ChatDispatchResult::immediate(ChatReply::text('This is not your reminder.'));

        self::post($client, self::interaction(3, ['custom_id' => 'refresh:hsr:12', 'component_type' => 2]));

        $response = self::json($client);
        $this->assertSame(4, $response['type']);
        $this->assertSame(64, $response['data']['flags']);
        $this->assertSame('This is not your reminder.', $response['data']['content']);
    }

    public function testAutocompleteAnswersTheSuggestionsWithinDiscordLimits(): void
    {
        $client = $this->createInteractionsClient();
        $choices = [new ChatChoice(str_repeat('n', 150), 'long-name')];
        for ($i = 1; $i <= 30; ++$i) {
            $choices[] = new ChatChoice('Profile '.$i, $i);
        }
        $this->dispatcher->choices = $choices;

        self::post($client, self::interaction(4, ['name' => 'remind', 'type' => 1, 'options' => [[
            'name' => 'stamina',
            'type' => 1,
            'options' => [
                ['name' => 'game', 'type' => 3, 'value' => 'hsr'],
                ['name' => 'profile', 'type' => 4, 'value' => '1', 'focused' => true],
            ],
        ]]]));

        $this->assertResponseIsSuccessful();
        $response = self::json($client);
        $this->assertSame(8, $response['type']);
        $this->assertCount(25, $response['data']['choices']);
        $this->assertSame(['name' => str_repeat('n', 99).'…', 'value' => 'long-name'], $response['data']['choices'][0]);
        $this->assertSame(['name' => 'Profile 24', 'value' => 24], $response['data']['choices'][24]);

        $call = $this->dispatcher->autocompletes[0];
        $this->assertSame(
            [ChatPlatform::Discord, self::USER_ID, 'remind', 'stamina', 'profile', '1', ['game' => 'hsr']],
            [$call['platform'], $call['platformUserId'], $call['command'], $call['subcommand'], $call['option'], $call['partial'], $call['arguments']],
        );
        $this->assertSame([], $this->dispatcher->commands);
    }

    public function testUnknownInteractionTypeIsABadRequest(): void
    {
        $client = $this->createInteractionsClient();

        // 5 = MODAL_SUBMIT: the bot opens no modal
        self::post($client, self::interaction(5, ['custom_id' => 'modal']));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame([], $this->dispatcher->commands);
        $this->assertSame([], $this->dispatcher->actions);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteInteractions(): iterable
    {
        $command = self::interaction(2, ['name' => 'stamina', 'type' => 1]);

        yield 'command without user' => [array_diff_key($command, ['member' => true])];
        yield 'command without name' => [self::interaction(2, ['type' => 1])];
        yield 'command data not an object' => [['data' => 'stamina'] + $command];
        yield 'command without token' => [array_diff_key($command, ['token' => true])];
        yield 'command without application id' => [array_diff_key($command, ['application_id' => true])];
        yield 'button without custom_id' => [self::interaction(3, ['component_type' => 2])];
    }

    /**
     * @param array<string, mixed> $interaction
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteInteractions')]
    public function testInteractionWithoutWhatItsTypeNeedsIsABadRequest(array $interaction): void
    {
        $client = $this->createInteractionsClient();

        self::post($client, $interaction);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame([], $this->dispatcher->commands);
        $this->assertSame([], $this->dispatcher->actions);
    }

    public function testUnsignedInteractionIsUnauthorizedWithoutCookie(): void
    {
        $client = $this->createInteractionsClient();

        self::post($client, self::interaction(2, ['name' => 'stamina', 'type' => 1]), signed: false);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame([], $this->dispatcher->commands);
        $this->assertNoCookie($client);
    }

    public function testWithoutPublicKeyTheEndpointDoesNotExist(): void
    {
        $client = $this->createInteractionsClient(configured: false);

        self::post($client, self::interaction(2, ['name' => 'stamina', 'type' => 1]));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame([], $this->dispatcher->commands);
    }
}
