<?php

namespace App\Tests\CoreBundle\Discord;

use App\CoreBundle\Exception\InvalidDiscordSignatureException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;
use App\CoreBundle\Attribute\MapDiscordInteraction;
use App\CoreBundle\Exception\InvalidDiscordInteractionException;
use App\CoreBundle\Model\Discord\Interaction\DiscordInteraction;
use App\CoreBundle\Model\Discord\Interaction\DiscordInteractionOption;
use App\CoreBundle\ValueResolver\DiscordInteractionValueResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * #[MapDiscordInteraction] with the serializer of the container (camelCase/snake_case name converter, property info),
 * and a key pair made for the test. The suite fails on any PHP warning, so the malformed signatures and keys also
 * prove that nothing reaches hex2bin() or sodium unchecked.
 */
class DiscordInteractionValueResolverTest extends KernelTestCase
{
    private const string TIMESTAMP = '1764000000';

    private static string $secretKey;
    private static string $publicKey;

    private BufferingLogger $logger;

    public static function setUpBeforeClass(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        self::$secretKey = sodium_crypto_sign_secretkey($keyPair);
        self::$publicKey = sodium_crypto_sign_publickey($keyPair);
    }

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        // BufferingLogger prints what is left when destroyed
        $this->logger->cleanLogs();
        parent::tearDown();
    }

    private function resolver(?string $publicKey = null): DiscordInteractionValueResolver
    {
        return new DiscordInteractionValueResolver(
            static::getContainer()->get(SerializerInterface::class),
            $publicKey ?? bin2hex(self::$publicKey),
            $this->logger,
        );
    }

    /**
     * @return list<string> "level: message" of the records
     */
    private function logs(): array
    {
        return array_map(static fn (array $record): string => $record[0].': '.$record[1], $this->logger->cleanLogs());
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $body, array $headers): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/discord/interactions', 'POST', server: $server, content: $body);
    }

    private static function signedRequest(string $body, ?string $secretKey = null): Request
    {
        return self::request($body, [
            DiscordInteractionValueResolver::SIGNATURE_HEADER => bin2hex(sodium_crypto_sign_detached(self::TIMESTAMP.$body, $secretKey ?? self::$secretKey)),
            DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP,
        ]);
    }

    private static function argument(): ArgumentMetadata
    {
        return new ArgumentMetadata('interaction', DiscordInteraction::class, false, false, null, attributes: [new MapDiscordInteraction()]);
    }

    private function resolve(Request $request, ?string $publicKey = null): DiscordInteraction
    {
        $resolved = [...$this->resolver($publicKey)->resolve($request, self::argument())];
        $this->assertCount(1, $resolved);
        $this->assertInstanceOf(DiscordInteraction::class, $resolved[0]);

        return $resolved[0];
    }

    private function assertRefused(int $status, Request $request, ?string $publicKey = null): void
    {
        try {
            $this->resolver($publicKey)->resolve($request, self::argument());
            $this->fail(sprintf('Expected HTTP %d.', $status));
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
            if ($status === 401) {
                // Logged as info: Discord sends wrong signatures on purpose
                $this->assertInstanceOf(InvalidDiscordSignatureException::class, $e);
                $this->assertSame(LogLevel::INFO, (new \ReflectionClass($e))->getAttributes(WithLogLevel::class)[0]->newInstance()->level);
            }
        }
    }

    public function testASignedCommandFromAServerIsDenormalizedWithItsNestedOptions(): void
    {
        $body = json_encode([
            'app_permissions' => '442368',
            'application_id' => '1180000000000000001',
            'authorizing_integration_owners' => ['0' => '1100000000000000000'],
            'channel_id' => '1000000000000000002',
            'context' => 0,
            'data' => [
                'id' => '1190000000000000000',
                'name' => 'remind',
                'type' => 1,
                'options' => [[
                    'name' => 'stamina',
                    'type' => 1,
                    'options' => [
                        ['name' => 'game', 'type' => 3, 'value' => 'hsr'],
                        ['name' => 'threshold', 'type' => 4, 'value' => 200],
                        ['name' => 'silent', 'type' => 5, 'value' => true],
                    ],
                ]],
            ],
            'entitlements' => [],
            'guild_id' => '1000000000000000003',
            'guild_locale' => 'en-US',
            'id' => '1210000000000000000',
            'locale' => 'it',
            'member' => [
                'nick' => null,
                'roles' => [],
                'user' => ['id' => '80351110224678912', 'username' => 'nelly', 'global_name' => 'Nelly'],
            ],
            'token' => 'aW50ZXJhY3Rpb246dGVzdA',
            'type' => 2,
            'version' => 1,
        ], \JSON_THROW_ON_ERROR);

        $interaction = $this->resolve(self::signedRequest($body));

        $this->assertSame(DiscordInteraction::TYPE_APPLICATION_COMMAND, $interaction->type);
        $this->assertSame('1210000000000000000', $interaction->id);
        $this->assertSame('1180000000000000001', $interaction->applicationId);
        $this->assertSame('aW50ZXJhY3Rpb246dGVzdA', $interaction->token);
        $this->assertSame('1000000000000000003', $interaction->guildId);
        $this->assertSame('80351110224678912', $interaction->getUserId());
        $this->assertSame('it', $interaction->getLanguage());
        $this->assertSame('remind', $interaction->data?->name);
        $this->assertSame(1, $interaction->data->type);

        $subcommand = $interaction->data->options[0];
        $this->assertInstanceOf(DiscordInteractionOption::class, $subcommand);
        $this->assertSame(['stamina', DiscordInteractionOption::TYPE_SUB_COMMAND, null], [$subcommand->name, $subcommand->type, $subcommand->value]);
        $this->assertSame(
            [['game', 3, 'hsr', false], ['threshold', 4, 200, false], ['silent', 5, true, false]],
            array_map(static fn (DiscordInteractionOption $option): array => [$option->name, $option->type, $option->value, $option->focused], $subcommand->options),
        );
        $this->assertSame([], $this->logs());
    }

    public function testAButtonPressedInADirectMessageGivesTheUserAndTheCustomId(): void
    {
        $body = '{"type":3,"id":"1","application_id":"2","token":"t","locale":"en-US","user":{"id":"80351110224678912"},'
            .'"data":{"custom_id":"refresh:hsr:1","component_type":2},"message":{"id":"3"}}';

        $interaction = $this->resolve(self::signedRequest($body));

        $this->assertSame(DiscordInteraction::TYPE_MESSAGE_COMPONENT, $interaction->type);
        $this->assertSame('80351110224678912', $interaction->getUserId());
        $this->assertNull($interaction->member);
        $this->assertNull($interaction->guildId);
        $this->assertSame('en', $interaction->getLanguage());
        $this->assertSame(['refresh:hsr:1', 2], [$interaction->data?->customId, $interaction->data->componentType]);
    }

    public function testAPingNeedsOnlyItsType(): void
    {
        $interaction = $this->resolve(self::signedRequest('{"type":1,"id":"1","application_id":"2","token":"t","version":1}'));

        $this->assertSame(DiscordInteraction::TYPE_PING, $interaction->type);
        $this->assertNull($interaction->getUserId());
        $this->assertNull($interaction->data);
    }

    public function testAnAutocompleteKeepsTheFocusedOption(): void
    {
        $body = '{"type":4,"application_id":"2","token":"t","user":{"id":"5"},"data":{"name":"stamina","type":1,'
            .'"options":[{"name":"profile","type":3,"value":"Nel","focused":true}]}}';

        $option = $this->resolve(self::signedRequest($body))->data?->options[0];

        $this->assertSame(['profile', 'Nel', true], [$option?->name, $option?->value, $option?->focused]);
    }

    public function testDisabledBotIsNotFoundBeforeAnyCheck(): void
    {
        $this->expectException(NotFoundHttpException::class);

        // Neither signed nor JSON: the disabled endpoint does not even look
        $this->resolver('')->resolve(self::request('not json', []), self::argument());
    }

    public function testAnotherKeysSignatureIsUnauthorized(): void
    {
        $this->assertRefused(401, self::signedRequest('{"type":1}', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
    }

    public function testATamperedBodyIsUnauthorized(): void
    {
        $request = self::signedRequest('{"type":1}');
        $tampered = self::request('{"type":2}', [
            DiscordInteractionValueResolver::SIGNATURE_HEADER => (string) $request->headers->get(DiscordInteractionValueResolver::SIGNATURE_HEADER),
            DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP,
        ]);

        $this->assertRefused(401, $tampered);
    }

    public function testAnotherTimestampIsUnauthorized(): void
    {
        $request = self::signedRequest('{"type":1}');
        $request->headers->set(DiscordInteractionValueResolver::TIMESTAMP_HEADER, '1764000001');

        $this->assertRefused(401, $request);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function malformedSignatureHeaders(): iterable
    {
        $valid = str_repeat('ab', \SODIUM_CRYPTO_SIGN_BYTES);
        yield 'no headers' => [[]];
        yield 'no signature' => [[DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP]];
        yield 'no timestamp' => [[DiscordInteractionValueResolver::SIGNATURE_HEADER => $valid]];
        yield 'empty signature' => [[DiscordInteractionValueResolver::SIGNATURE_HEADER => '', DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP]];
        yield 'not hex' => [[DiscordInteractionValueResolver::SIGNATURE_HEADER => str_repeat('zz', \SODIUM_CRYPTO_SIGN_BYTES), DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP]];
        yield 'odd length' => [[DiscordInteractionValueResolver::SIGNATURE_HEADER => substr($valid, 1), DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP]];
        yield 'too short' => [[DiscordInteractionValueResolver::SIGNATURE_HEADER => substr($valid, 2), DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP]];
        yield 'too long' => [[DiscordInteractionValueResolver::SIGNATURE_HEADER => $valid.'ab', DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP]];
        yield 'multibyte' => [[DiscordInteractionValueResolver::SIGNATURE_HEADER => str_repeat('é', \SODIUM_CRYPTO_SIGN_BYTES), DiscordInteractionValueResolver::TIMESTAMP_HEADER => self::TIMESTAMP]];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('malformedSignatureHeaders')]
    public function testMalformedSignatureHeadersAreUnauthorized(array $headers): void
    {
        $this->assertRefused(401, self::request('{"type":1}', $headers));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPublicKeys(): iterable
    {
        yield 'not hex' => [str_repeat('zz', \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)];
        yield 'odd length' => [str_repeat('a', 2 * \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES - 1)];
        yield 'too short' => [str_repeat('ab', \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES - 1)];
        yield 'base64 instead of hex' => [base64_encode(random_bytes(\SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))];
    }

    #[DataProvider('malformedPublicKeys')]
    public function testAMalformedPublicKeyRefusesEveryInteractionAndSaysWhy(string $publicKey): void
    {
        $this->assertRefused(401, self::signedRequest('{"type":1}'), $publicKey);
        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertStringStartsWith('error: DISCORD_PUBLIC_KEY is not the hex public key', $logs[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function signedMalformedBodies(): iterable
    {
        yield 'not JSON' => ['{"type":'];
        yield 'empty' => [''];
        yield 'empty list' => ['[]'];
        yield 'empty object' => ['{}'];
        yield 'string' => ['"x"'];
        yield 'number' => ['1'];
        yield 'null' => ['null'];
        yield 'type as a string' => ['{"type":"a"}'];
        yield 'option without name' => ['{"type":2,"data":{"name":"remind","options":[{"type":3,"value":"x"}]}}'];
        yield 'options not a list' => ['{"type":2,"data":{"name":"remind","options":"x"}}'];
        yield 'user id not a string' => ['{"type":2,"user":{"id":["x"]}}'];
    }

    #[DataProvider('signedMalformedBodies')]
    public function testASignedBodyThatIsNotAnInteractionIsABadRequest(string $body): void
    {
        $this->expectException(InvalidDiscordInteractionException::class);

        $this->resolver()->resolve(self::signedRequest($body), self::argument());
    }

    public function testOnlyADiscordInteractionArgumentCanBeMapped(): void
    {
        $this->expectException(\LogicException::class);

        $this->resolver()->resolve(self::signedRequest('{"type":1}'), new ArgumentMetadata('body', 'string', false, false, null, attributes: [new MapDiscordInteraction()]));
    }
}
