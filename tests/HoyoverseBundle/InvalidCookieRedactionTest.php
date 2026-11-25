<?php

namespace App\Tests\HoyoverseBundle;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\ConfigurationException;
use App\HoyoverseBundle\Message\Profile\StaminaCheckProfileMessage;
use App\HoyoverseBundle\Message\UpdateCookieMessage;
use App\HoyoverseBundle\MessageHandler\Profile\StaminaCheckProfileHandler;
use App\HoyoverseBundle\MessageHandler\UpdateCookieMessageHandler;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabManagerService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * A HoYoLAB cookie holds the account credentials: when it cannot be parsed, the error names the account and never
 * carries the cookie, neither in the exception message, nor in the logs, nor in the arguments of the stack trace.
 * Real cookie parsing everywhere: a stubbed parser would prove nothing about what its exception says.
 */
class InvalidCookieRedactionTest extends TestCase
{
    private const string SECRET = 'v2_SECRET_LTOKEN';

    // No ltmid_v2: the cookie cannot be parsed
    private const string INVALID_COOKIE = 'ltoken_v2=' . self::SECRET . '; ltuid_v2=111';

    private const int ACCOUNT_ID = 42;

    private const string EXPECTED_MESSAGE = 'The HoYoLAB cookie of account #42 is missing or invalid.';

    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
    }

    private function cookieUtils(): HoyolabCookieUtilsService
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory))]);

        // No HTTP response: reaching HoYoverse would fail with another exception than the expected one
        return new HoyolabCookieUtilsService(new MockHttpClient([]), $this->logger, $serializer, $this->createStub(HoyolabUtilsService::class));
    }

    /**
     * @param ?string $cookie null leaves the cookie unset
     */
    private static function account(?string $cookie): HoyoverseAccount
    {
        $account = (new HoyoverseAccount())->setUser(new User());
        if ($cookie !== null) {
            $account->setCookie($cookie);
        }
        (new \ReflectionProperty(HoyoverseAccount::class, 'id'))->setValue($account, self::ACCOUNT_ID);

        return $account;
    }

    private static function profile(?string $cookie): HoyoverseGameProfile
    {
        return (new HoyoverseGameProfile())
            ->setAccount(self::account($cookie))
            ->setGameId(1)
            ->setGameBiz('hk4e_global')
            ->setGameUid('100000001')
            ->setRegion('os_euro')
            ->setNickname('Traveler');
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableCookieProvider(): iterable
    {
        yield 'cookie without ltmid_v2' => [self::INVALID_COOKIE];
        yield 'empty cookie' => [''];
        yield 'no cookie' => [null];
    }

    /**
     * Runs the call, expecting the ConfigurationException that names the account, and checks that neither the
     * exception nor the logs carry the cookie.
     */
    private function assertFailsNamingTheAccount(callable $call): void
    {
        try {
            $call();
            $this->fail('An unusable cookie must fail.');
        } catch (ConfigurationException $e) {
            $this->assertSame(self::EXPECTED_MESSAGE, $e->getMessage());
        }

        $this->assertLogsDoNotCarryTheCookie();
    }

    /**
     * @return list<string> The log messages, once checked
     */
    private function assertLogsDoNotCarryTheCookie(): array
    {
        $messages = [];
        foreach ($this->logger->cleanLogs() as [, $message, $context]) {
            $this->assertStringNotContainsString(self::SECRET, $message . "\n" . self::flatten($context));
            $messages[] = $message;
        }

        return $messages;
    }

    private static function flatten(mixed $value): string
    {
        return match (true) {
            $value instanceof \Throwable => $value->getMessage() . "\n" . self::flatten($value->getPrevious()),
            \is_array($value) => implode("\n", array_map(self::flatten(...), $value)),
            $value === null => '',
            \is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }

    public function testParseCookieNamesOnlyTheMissingTokens(): void
    {
        try {
            $this->cookieUtils()->parseCookie(self::INVALID_COOKIE);
            $this->fail('A cookie without ltmid_v2 must not be parsed.');
        } catch (ConfigurationException $e) {
            $this->assertSame('The cookie lacks ltmid_v2.', $e->getMessage());
        }
    }

    /**
     * With zend.exception_ignore_args off (the development php.ini), a trace keeps the arguments of every call,
     * and Messenger stores the trace of a failed message.
     */
    public function testTraceArgumentsDoNotCarryTheCookie(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $this->cookieUtils()->parseCookie(self::INVALID_COOKIE);
            $this->fail('A cookie without ltmid_v2 must not be parsed.');
        } catch (ConfigurationException $e) {
            $parseFrame = array_find($e->getTrace(), static fn (array $frame): bool => $frame['function'] === 'parseCookie');
            $this->assertNotNull($parseFrame);
            $this->assertInstanceOf(\SensitiveParameterValue::class, $parseFrame['args'][0] ?? null);

            foreach ($e->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    $this->assertFalse(\is_string($argument) && str_contains($argument, self::SECRET), sprintf('%s() received the cookie in the trace.', $frame['function']));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    #[DataProvider('unusableCookieProvider')]
    public function testProfileTaskFailsNamingTheAccount(?string $cookie): void
    {
        $repository = $this->createStub(HoyoverseGameProfileRepository::class);
        $repository->method('find')->willReturn(self::profile($cookie));

        $locator = $this->createMock(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->expects($this->never())->method('get');

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');

        $handler = new StaminaCheckProfileHandler($repository, $this->cookieUtils(), $locator, $this->logger, $notificationService);

        // The exception still reaches Messenger, which retries the message as before
        $this->assertFailsNamingTheAccount(static fn () => $handler(new StaminaCheckProfileMessage(1)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function profileSyncProvider(): iterable
    {
        yield 'from the game roles' => ['updateCachedGameProfilesByGameRoles'];
        yield 'from the game records' => ['updateCachedGameProfilesByGameRecords'];
    }

    #[DataProvider('profileSyncProvider')]
    public function testGameProfileSyncFailsNamingTheAccount(string $method): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('getRepository');
        $entityManager->expects($this->never())->method('flush');

        $manager = new HoyolabManagerService(
            $this->logger,
            $entityManager,
            $this->createStub(ServiceCollectionInterface::class),
            $this->createStub(ServiceCollectionInterface::class),
        );
        $manager->setCookieUtils($this->cookieUtils());

        $this->assertFailsNamingTheAccount(static fn () => $manager->{$method}(self::account(self::INVALID_COOKIE)));
    }

    public function testAuthkeyGenerationFailsNamingTheAccount(): void
    {
        // The message of this exception is shown to the user by the pull sync tracker
        $cookieUtils = $this->cookieUtils();

        $this->assertFailsNamingTheAccount(static fn () => $cookieUtils->generateAuthKey(self::profile(self::INVALID_COOKIE)));
    }

    public function testCookieRefreshLogsTheAccountAndGoesOn(): void
    {
        $repository = $this->createStub(HoyoverseAccountRepository::class);
        $repository->method('findAll')->willReturn([self::account(self::INVALID_COOKIE)]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');

        $handler = new UpdateCookieMessageHandler($repository, $this->cookieUtils(), $entityManager, $notificationService);
        $handler(new UpdateCookieMessage());

        $this->assertSame(
            ['Error updating cookie for account 42: ' . self::EXPECTED_MESSAGE],
            $this->assertLogsDoNotCarryTheCookie(),
        );
    }

    protected function tearDown(): void
    {
        // Nothing left for the destructor of the logger to print
        $this->logger->cleanLogs();
    }
}
