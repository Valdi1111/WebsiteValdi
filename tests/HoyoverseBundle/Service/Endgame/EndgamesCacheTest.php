<?php

namespace App\Tests\HoyoverseBundle\Service\Endgame;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Presenter\EndgamesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Service\Endgame\EndgameStatusBuilder;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveShiyuDefenseDataException;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\EndgamesReminderProfileHandler;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Per-profile cache of the battle record requests of the HoYoLAB endgames traits (CachedEndgameRequestTrait), with
 * the application serializer.
 */
class EndgamesCacheTest extends KernelTestCase
{
    private const string NOW = '2026-03-17 15:00:00';

    /** @var list<array{endpoint: string, query: array<string, string>}> */
    private array $requests = [];

    /** @var array<string, list<array<string, mixed>>> answers queued per endpoint (last part of the path) */
    private array $answers = [];

    private ArrayAdapter $cache;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(1800);
        $this->clock = new MockClock(self::NOW, 'Europe/Paris');
    }

    /**
     * @template T of GameService
     * @param class-string<T>                     $class
     * @param array<string, array<string, mixed>> $defaults answer per endpoint once its queue is empty
     * @return T
     */
    private function createService(string $class, array $defaults): GameService
    {
        self::bootKernel();

        $service = new $class(new NullLogger());
        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setHoyolabUtils(new HoyolabUtilsService());
        $service->setEndgamesCache($this->cache);
        $service->setNotesClock($this->clock);
        if ($service instanceof HonkaiStarRailService || $service instanceof ZenlessZoneZeroService) {
            $service->setServerTimezoneResolver(new HoyoverseServerTimezoneResolver());
        }
        if ($service instanceof GenshinImpactService || $service instanceof HonkaiStarRailService) {
            $service->setEndgamesClock($this->clock);
        }
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url) use ($defaults): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $endpoint = basename((string) parse_url($url, PHP_URL_PATH));
            $this->requests[] = ['endpoint' => $endpoint, 'query' => $query];

            $body = ($this->answers[$endpoint] ?? []) !== []
                ? array_shift($this->answers[$endpoint])
                : $defaults[$endpoint] ?? ['retcode' => -1, 'message' => 'unexpected request'];

            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        }));

        return $service;
    }

    private function createZenless(): ZenlessZoneZeroService
    {
        return $this->createService(ZenlessZoneZeroService::class, [
            'hadal_info_v2' => ZenlessEndgameFixture::shiyuDefense(),
            'hadal_mem_detail_v2' => ZenlessEndgameFixture::deadlyAssault(),
        ]);
    }

    private function createAccount(int $gameId = ZenlessZoneZeroService::GAME_ID, ?int $profileId = 42, string $gameUid = '1300000001'): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())
            ->setGameId($gameId)
            ->setGameUid($gameUid)
            ->setRegion($gameId === HonkaiStarRailService::GAME_ID ? 'prod_official_eur' : 'os_euro')
            ->setNickname('Proxy');
        if ($profileId !== null) {
            (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, $profileId);
        }

        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile($profile);
    }

    /**
     * The reminder handler around $service for the profile of $account, at 15:00 on 2026-03-19 (inside the windows
     * of both Zenless Zone Zero modes).
     *
     * @param list<UniversalEmbed> $sent notifications sent
     */
    private function createReminder(ZenlessZoneZeroService $service, RuntimeAccountData $account, array &$sent): EndgamesReminderProfileHandler
    {
        $profile = $account->getGameProfile()
            ->setAccount(new HoyoverseAccount()->setUser(new User())->setCookie('ltoken_v2=test; ltuid_v2=123;'))
            ->setGameName('Zenless Zone Zero')
            ->setEndgamesCheck(true)
            ->setNotificationPlatforms(['discord']);

        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);
        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($service);
        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('tryParseCookie')->willReturn($account->getParsedCookie());
        $notificationService = $this->createStub(UnifiedNotificationService::class);
        $notificationService->method('sendToUser')->willReturnCallback(function (User $user, UniversalEmbed $embed) use (&$sent): void {
            $sent[] = $embed;
        });

        $handler = new EndgamesReminderProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler->setEndgamesConfig(static::getContainer()->getParameter('hoyoverse.endgames_reminder'));
        $clock = new MockClock('2026-03-19 15:00:00', 'Europe/Paris');
        $handler->setClock($clock);
        $handler->setEndgamesPresenter(new EndgamesPresenter(new GameProfileEmbedFactory($clock), new DurationFormatter()));
        $handler->setEndgameStatusBuilder(new EndgameStatusBuilder());

        return $handler;
    }

    private static function definition(string $key): EndgameDefinition
    {
        foreach (ZenlessZoneZeroService::getEndgameDefinitions() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        throw new \LogicException(sprintf('Mode "%s" not declared', $key));
    }

    private static function timestamp(string $time = self::NOW): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('Europe/Paris')))->getTimestamp();
    }

    /**
     * @return list<string> keys of the entries stored (the adapter also lists the misses, with null)
     */
    private function cacheKeys(): array
    {
        return array_keys(array_filter($this->cache->getValues(), static fn (mixed $value) => $value !== null));
    }

    /**
     * @return iterable<string, array{0: class-string<GameService>, 1: array<string, array<string, mixed>>}>
     */
    public static function games(): iterable
    {
        // Endpoints in the order of the modes
        yield 'Genshin Impact' => [GenshinImpactService::class, [
            'spiralAbyss' => GenshinEndgameFixture::spiralAbyss(),
            'role_combat' => GenshinEndgameFixture::theater(),
            'hard_challenge' => GenshinEndgameFixture::stygianOnslaught(),
        ]];
        yield 'Honkai: Star Rail' => [HonkaiStarRailService::class, [
            'challenge' => HonkaiStarRailEndgameFixture::memoryOfChaos(),
            'challenge_story' => HonkaiStarRailEndgameFixture::pureFiction(),
            'challenge_boss' => HonkaiStarRailEndgameFixture::apocalypticShadow(),
            'challenge_peak' => HonkaiStarRailEndgameFixture::anomalyArbitration(),
        ]];
        yield 'Zenless Zone Zero' => [ZenlessZoneZeroService::class, [
            'hadal_info_v2' => ZenlessEndgameFixture::shiyuDefense(),
            'hadal_mem_detail_v2' => ZenlessEndgameFixture::deadlyAssault(),
        ]];
    }

    /**
     * @param class-string<GameService>           $class
     * @param array<string, array<string, mixed>> $answers
     */
    #[DataProvider('games')]
    public function testEveryModeIsCachedPerRequest(string $class, array $answers): void
    {
        $service = $this->createService($class, $answers);
        $account = $this->createAccount($class::GAME_ID);
        $definitions = $class::getEndgameDefinitions();

        foreach ($definitions as $definition) {
            $endgames = $service->fetchEndgame($definition, $account);
            $this->assertNotSame([], $endgames);
            // Every variant carries the fetch time of the response
            foreach ($endgames as $endgame) {
                $this->assertSame(self::timestamp(), $endgame->getFetchedAt()?->getTimestamp(), $definition->key);
            }
        }
        $this->assertSame(array_keys($answers), array_column($this->requests, 'endpoint'));
        $this->assertCount(count($definitions), $this->cacheKeys());
        foreach ($this->cacheKeys() as $key) {
            $this->assertStringStartsWith('endgame_42_', $key);
        }

        // The second read makes no request
        foreach ($definitions as $definition) {
            $service->fetchEndgame($definition, $account);
        }
        $this->assertCount(count($definitions), $this->requests);

        // fresh requests every mode again
        foreach ($definitions as $definition) {
            $service->fetchEndgame($definition, $account, fresh: true);
        }
        $this->assertSame([...array_keys($answers), ...array_keys($answers)], array_column($this->requests, 'endpoint'));
        $this->assertCount(count($definitions), $this->cacheKeys());
    }

    public function testEntryHoldsTheRawPayloadAndTheFetchTime(): void
    {
        $service = $this->createZenless();

        $service->fetchEndgame(self::definition(ZenlessZoneZeroService::ENDGAME_DEADLY_ASSAULT), $this->createAccount());

        $keys = $this->cacheKeys();
        $this->assertCount(1, $keys);
        $this->assertMatchesRegularExpression('/^endgame_42_[0-9a-f]+$/', $keys[0]);
        $this->assertSame(
            ['fetchedAt' => self::timestamp(), 'data' => ZenlessEndgameFixture::deadlyAssault()['data']],
            $this->cache->getItem($keys[0])->get()
        );
    }

    public function testEachProfileAndRequestHasItsOwnEntry(): void
    {
        $service = $this->createZenless();
        // Same game account linked to two profiles: the profile id alone keeps the entries apart
        $first = $this->createAccount(profileId: 1);
        $second = $this->createAccount(profileId: 2);

        foreach ([$first, $second, $first, $second] as $account) {
            foreach (ZenlessZoneZeroService::getEndgameDefinitions() as $definition) {
                $service->fetchEndgame($definition, $account);
            }
        }

        $this->assertCount(4, $this->requests);
        $keys = $this->cacheKeys();
        $this->assertCount(4, $keys);
        $this->assertCount(2, array_filter($keys, static fn (string $key) => str_starts_with($key, 'endgame_1_')));
        $this->assertCount(2, array_filter($keys, static fn (string $key) => str_starts_with($key, 'endgame_2_')));
    }

    public function testPublicGetterSharesTheEntryOfItsMode(): void
    {
        $service = $this->createZenless();
        $account = $this->createAccount();

        $this->assertSame(9, $service->getDeadlyAssault($account)->getStars());
        $service->fetchEndgame(self::definition(ZenlessZoneZeroService::ENDGAME_DEADLY_ASSAULT), $account);
        $this->assertCount(1, $this->requests);

        $service->getDeadlyAssault($account, fresh: true);
        $this->assertCount(2, $this->requests);
    }

    public function testFreshCallsTheApiAndReplacesTheEntry(): void
    {
        $this->answers['hadal_mem_detail_v2'] = [
            ZenlessEndgameFixture::deadlyAssault(),
            ZenlessEndgameFixture::deadlyAssault([[25120, 3, 'Example Boss A'], [31870, 1, 'Example Boss B'], [44300, 3, 'Example Boss C']]),
        ];
        $service = $this->createZenless();
        $account = $this->createAccount();
        $deadlyAssault = self::definition(ZenlessZoneZeroService::ENDGAME_DEADLY_ASSAULT);

        $this->assertTrue($service->fetchEndgame($deadlyAssault, $account)[0]->isMaxed());
        $this->assertFalse($service->fetchEndgame($deadlyAssault, $account, fresh: true)[0]->isMaxed());
        $this->assertFalse($service->fetchEndgame($deadlyAssault, $account)[0]->isMaxed());

        $this->assertCount(2, $this->requests);
    }

    public function testErrorIsNotCached(): void
    {
        $this->answers['hadal_info_v2'] = [['retcode' => 10102, 'message' => 'Data is not public for the user', 'data' => null]];
        $service = $this->createZenless();
        $account = $this->createAccount();
        $shiyuDefense = self::definition(ZenlessZoneZeroService::ENDGAME_SHIYU_DEFENSE);

        try {
            $service->fetchEndgame($shiyuDefense, $account);
            $this->fail('The error retcode should throw');
        } catch (RetrieveShiyuDefenseDataException) {
        }
        $this->assertSame([], $this->cacheKeys());

        $this->assertCount(1, $service->fetchEndgame($shiyuDefense, $account));
        $this->assertCount(2, $this->requests);
        $this->assertCount(1, $this->cacheKeys());
    }

    public function testAnswerWithoutDataOrRetcodeIsNotCached(): void
    {
        $data = ZenlessEndgameFixture::deadlyAssault()['data'];
        $this->answers['hadal_mem_detail_v2'] = [
            ['retcode' => 0, 'message' => 'OK', 'data' => null],
            // No retcode: not an error for the client, not a success either
            ['data' => $data],
        ];
        $service = $this->createZenless();
        $account = $this->createAccount();
        $deadlyAssault = self::definition(ZenlessZoneZeroService::ENDGAME_DEADLY_ASSAULT);

        $this->assertFalse($service->fetchEndgame($deadlyAssault, $account)[0]->isPlayed());
        $this->assertSame([], $this->cacheKeys());
        $this->assertTrue($service->fetchEndgame($deadlyAssault, $account)[0]->isPlayed());
        $this->assertSame([], $this->cacheKeys());

        // The default answer has retcode 0 and data: cached
        $service->fetchEndgame($deadlyAssault, $account);
        $service->fetchEndgame($deadlyAssault, $account);
        $this->assertCount(3, $this->requests);
    }

    public function testProfileWithoutIdIsNeverCached(): void
    {
        $service = $this->createZenless();
        $account = $this->createAccount(profileId: null);
        $deadlyAssault = self::definition(ZenlessZoneZeroService::ENDGAME_DEADLY_ASSAULT);

        $service->fetchEndgame($deadlyAssault, $account);
        $service->fetchEndgame($deadlyAssault, $account);

        $this->assertCount(2, $this->requests);
        $this->assertSame([], $this->cacheKeys());
    }

    public function testEndgamesCarryTheFetchTimeOfTheirEntry(): void
    {
        $service = $this->createZenless();
        $account = $this->createAccount();
        $deadlyAssault = self::definition(ZenlessZoneZeroService::ENDGAME_DEADLY_ASSAULT);

        $service->fetchEndgame($deadlyAssault, $account);
        $this->clock->sleep(600);

        // Cached: the time of the answer, not of the read, for every variant
        $cached = $service->fetchEndgame($deadlyAssault, $account);
        $this->assertCount(2, $cached);
        foreach ($cached as $endgame) {
            $this->assertSame(self::timestamp(), $endgame->getFetchedAt()?->getTimestamp());
            // In the timezone of the clock, like every other time of the embeds
            $this->assertSame('Europe/Paris', $endgame->getFetchedAt()->getTimezone()->getName());
        }

        foreach ($service->fetchEndgame($deadlyAssault, $account, fresh: true) as $endgame) {
            $this->assertSame(self::timestamp() + 600, $endgame->getFetchedAt()?->getTimestamp());
        }
    }

    public function testReminderRequestsTheModesAgainAndReplacesTheEntries(): void
    {
        // Cached while Deadly Assault was not played; the player has cleared it since
        $this->answers['hadal_mem_detail_v2'] = [ZenlessEndgameFixture::deadlyAssault([], [], hasHard: false)];
        $service = $this->createZenless();
        $account = $this->createAccount();
        $deadlyAssault = self::definition(ZenlessZoneZeroService::ENDGAME_DEADLY_ASSAULT);
        $service->fetchEndgame(self::definition(ZenlessZoneZeroService::ENDGAME_SHIYU_DEFENSE), $account);
        $this->assertFalse($service->fetchEndgame($deadlyAssault, $account)[0]->isPlayed());

        $sent = [];
        $this->createReminder($service, $account, $sent)(new EndgamesReminderProfileMessage(42));

        // Every mode requested again: the stale "not played" entry reminds nothing
        $this->assertSame(['hadal_info_v2', 'hadal_mem_detail_v2', 'hadal_info_v2', 'hadal_mem_detail_v2'], array_column($this->requests, 'endpoint'));
        $this->assertSame([], $sent);
        // The new answers replaced the entries
        $this->assertTrue($service->fetchEndgame($deadlyAssault, $account)[0]->isPlayed());
        $this->assertCount(4, $this->requests);
    }

    public function testContainerWiresTheEndgamesPool(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        foreach ([GenshinImpactService::class, HonkaiStarRailService::class, ZenlessZoneZeroService::class] as $class) {
            $this->assertSame($container->get('hoyoverse.endgames.cache'), $container->get($class)->getEndgamesCache(), $class);
        }
    }
}
