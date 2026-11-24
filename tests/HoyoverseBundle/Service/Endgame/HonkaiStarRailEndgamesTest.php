<?php

namespace App\Tests\HoyoverseBundle\Service\Endgame;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Presenter\EndgamesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveAnomalyArbitrationDataException;
use App\HoyoverseBundle\Exception\RetrieveApocalypticShadowDataException;
use App\HoyoverseBundle\Exception\RetrieveMemoryOfChaosDataException;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\EndgamesReminderProfileHandler;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * HonkaiStarRailEndgamesTrait against HoYoLAB-shaped responses, denormalized by the application serializer.
 */
class HonkaiStarRailEndgamesTest extends KernelTestCase
{
    /** 2026-11-25 15:00 in Paris (UTC+01:00): inside every period of the fixture */
    private const string NOW = '2026-11-25 15:00:00';

    /** @var list<array{path: string, query: array<string, string>, headers: list<string>}> */
    private array $requests = [];

    /**
     * @param array<string, array<string, mixed>> $bodies response body by endpoint (challenge, challenge_story,
     *                                                    challenge_boss, challenge_peak); the others answer
     *                                                    "player not played" shapes
     */
    private function createService(array $bodies = [], ?LoggerInterface $logger = null, string $now = self::NOW): HonkaiStarRailService
    {
        self::bootKernel();

        $bodies += [
            'challenge' => HonkaiStarRailEndgameFixture::memoryOfChaos(),
            'challenge_story' => HonkaiStarRailEndgameFixture::pureFiction(),
            'challenge_boss' => HonkaiStarRailEndgameFixture::apocalypticShadow(),
            'challenge_peak' => HonkaiStarRailEndgameFixture::anomalyArbitration(),
        ];

        $service = new HonkaiStarRailService($logger ?? new NullLogger());
        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setServerTimezoneResolver(new HoyoverseServerTimezoneResolver());
        $service->setHoyolabUtils(new HoyolabUtilsService());
        $service->setEndgamesClock(new MockClock($now, 'Europe/Paris'));
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url, array $options) use ($bodies): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->requests[] = ['path' => $path, 'query' => $query, 'headers' => array_map('strval', $options['headers'] ?? [])];

            return new MockResponse(json_encode($bodies[basename($path)] ?? ['retcode' => -1, 'message' => 'unexpected endpoint'], JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        }));

        return $service;
    }

    private function createAccount(string $region = 'prod_official_eur'): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())
            ->setGameId(HonkaiStarRailService::GAME_ID)
            ->setGameUid('700000001')
            ->setRegion($region)
            ->setNickname('Proxy');

        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile($profile);
    }

    /**
     * Every variant of every mode, by name.
     *
     * @return array<string, GameEndgame>
     */
    private function endgamesByName(HonkaiStarRailService $service, string $region = 'prod_official_eur'): array
    {
        $endgames = [];
        foreach (HonkaiStarRailService::getEndgameDefinitions() as $definition) {
            foreach ($service->fetchEndgame($definition, $this->createAccount($region)) as $endgame) {
                $endgames[$endgame->getName()] = $endgame;
            }
        }

        return $endgames;
    }

    /**
     * The reminder handler around $service, for an EU profile with the given settings.
     *
     * @param array<string, mixed>|null $settings endgames_settings of the profile
     * @param list<UniversalEmbed>      $sent     notifications sent
     */
    private function createHandler(
        HonkaiStarRailService $service,
        ?array $settings,
        array &$sent,
        string $now = self::NOW,
        ?LoggerInterface $logger = null,
    ): EndgamesReminderProfileHandler {
        $account = new HoyoverseAccount()->setUser(new User())->setCookie('ltoken_v2=test; ltuid_v2=123;');
        $profile = $this->createAccount()->getGameProfile()
            ->setAccount($account)
            ->setGameName('Honkai: Star Rail')
            ->setEndgamesCheck(true)
            ->setEndgamesSettings($settings)
            ->setNotificationPlatforms(['discord']);

        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);
        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($service);
        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('parseCookie')->willReturn($this->createAccount()->getParsedCookie());
        $notificationService = $this->createStub(UnifiedNotificationService::class);
        $notificationService->method('sendToUser')->willReturnCallback(function (User $user, UniversalEmbed $embed) use (&$sent): void {
            $sent[] = $embed;
        });

        $handler = new EndgamesReminderProfileHandler($repo, $cookieUtils, $locator, $logger ?? new NullLogger(), $notificationService);
        $handler->setEndgamesConfig(static::getContainer()->getParameter('hoyoverse.endgames_reminder'));
        $clock = new MockClock($now, 'Europe/Paris');
        $handler->setClock($clock);
        $handler->setEndgamesPresenter(new EndgamesPresenter(new GameProfileEmbedFactory($clock), new DurationFormatter()));

        return $handler;
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}> name, value and icon of the endgame fields, header excluded
     */
    private static function endgameFields(UniversalEmbed $embed): array
    {
        return array_map(
            static fn (EmbedField $field) => [$field->getName(), $field->getValue(), $field->getIcon()],
            array_slice($embed->getFields(), 3)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorBody(): array
    {
        return ['retcode' => 10102, 'message' => 'Data is not public for the user', 'data' => null];
    }

    public function testContainerWiresTheTimezoneResolver(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(
            HoyoverseServerTimezoneResolver::class,
            static::getContainer()->get(HonkaiStarRailService::class)->getServerTimezoneResolver()
        );
    }

    public function testContainerWiresTheEndgamesClock(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(ClockInterface::class, static::getContainer()->get(HonkaiStarRailService::class)->getEndgamesClock());
    }

    public function testContainerWiresTheReminderWindows(): void
    {
        self::bootKernel();

        $handler = static::getContainer()->get(EndgamesReminderProfileHandler::class);
        foreach (HonkaiStarRailService::getEndgameDefinitions() as $definition) {
            $this->assertSame(7, $handler->getDaysBeforeEnd('hsr', $definition));
        }
        $this->assertSame(
            ['memory_of_chaos', 'pure_fiction', 'apocalyptic_shadow', 'anomaly_arbitration'],
            array_keys(static::getContainer()->getParameter('hoyoverse.endgames_reminder')['hsr'])
        );
    }

    public function testDeclaresTheFourModesWithTheirVariants(): void
    {
        $this->assertSame('hsr', HonkaiStarRailService::getEndgamesConfigKey());

        $declared = [];
        foreach (HonkaiStarRailService::getEndgameDefinitions() as $definition) {
            foreach ($definition->variants as $variant) {
                $declared[$definition->key][$variant->key] = [$variant->name, $variant->defaultReminder];
            }
        }

        $this->assertSame([
            'memory_of_chaos' => [
                'reminder' => ['Memory of Chaos', EndgameReminder::NotPlayed],
                'starward_reminder' => ['Memory of Chaos (Starward)', EndgameReminder::Off],
            ],
            'pure_fiction' => [
                'reminder' => ['Pure Fiction', EndgameReminder::NotPlayed],
                'starward_reminder' => ['Pure Fiction (Starward)', EndgameReminder::Off],
            ],
            'apocalyptic_shadow' => [
                'reminder' => ['Apocalyptic Shadow', EndgameReminder::NotPlayed],
                'starward_reminder' => ['Apocalyptic Shadow (Starward)', EndgameReminder::Off],
            ],
            // No unlock flag and no Stellar Jade: off until the player asks, Plight too
            'anomaly_arbitration' => [
                'reminder' => ['Anomaly Arbitration', EndgameReminder::Off],
                'hard_reminder' => ['Anomaly Arbitration (Plight)', EndgameReminder::Off],
            ],
        ], $declared);
    }

    /**
     * Same calls as ApiGameProfilesController::apiGameProfilesUpdateSettings() and apiGameProfilesIdGet().
     */
    public function testEndgameSettingsAreSavedForStarRail(): void
    {
        self::bootKernel();
        $serializer = static::getContainer()->get('serializer');
        $profile = (new HoyoverseGameProfile())->setGameId(HonkaiStarRailService::GAME_ID);

        $serializer->denormalize([
            'endgames_check' => true,
            'endgames_settings' => [
                'pure_fiction' => ['reminder' => 'full_clear', 'starward_reminder' => 'not_played'],
                // Unknown variant and unknown mode: not stored
                'memory_of_chaos' => ['hard_reminder' => 'full_clear'],
                'deadly_assault' => ['reminder' => 'full_clear'],
            ],
        ], HoyoverseGameProfile::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $profile]);

        $this->assertTrue($profile->isEndgamesCheck());
        $this->assertSame('full_clear', $profile->getEndgamesSettings()['pure_fiction']['reminder']);
        $this->assertSame('not_played', $profile->getEndgamesSettings()['pure_fiction']['starward_reminder']);
        $this->assertArrayNotHasKey('deadly_assault', $profile->getEndgamesSettings());
        $this->assertArrayNotHasKey('hard_reminder', $profile->getEndgamesSettings()['memory_of_chaos']);

        $json = json_decode($serializer->serialize($profile->setEndgamesSettings(null), 'json'), true);
        $this->assertSame(['memory_of_chaos', 'pure_fiction', 'apocalyptic_shadow', 'anomaly_arbitration'], array_column($json['endgames'], 'key'));
        $this->assertSame('off', $json['endgames_settings']['anomaly_arbitration']['reminder']);
        $this->assertSame('not_played', $json['endgames_settings']['memory_of_chaos']['reminder']);
    }

    public function testRequestsTheFourEndpointsWithTheDsHeader(): void
    {
        $service = $this->createService();
        $account = $this->createAccount();

        $service->getMemoryOfChaos($account);
        $service->getPureFiction($account);
        $service->getApocalypticShadow($account);
        $service->getAnomalyArbitration($account);

        $this->assertSame(
            ['/game_record/hkrpg/api/challenge', '/game_record/hkrpg/api/challenge_story', '/game_record/hkrpg/api/challenge_boss', '/game_record/hkrpg/api/challenge_peak'],
            array_column($this->requests, 'path')
        );
        $base = ['role_id' => '700000001', 'server' => 'prod_official_eur', 'schedule_type' => '1'];
        $this->assertSame($base + ['need_all' => 'true'], $this->requests[0]['query']);
        $this->assertSame($base + ['need_all' => 'true'], $this->requests[1]['query']);
        $this->assertSame($base + ['need_all' => 'true'], $this->requests[2]['query']);
        // Anomaly Arbitration takes no need_all
        $this->assertSame($base, $this->requests[3]['query']);
        // Without DS the answer is -10001
        foreach ($this->requests as $request) {
            $this->assertCount(1, array_filter($request['headers'], static fn (string $header) => preg_match('/^ds: \d+,\w+,\w+$/i', $header) === 1));
        }
    }

    public function testDenormalizesMemoryOfChaos(): void
    {
        $data = $this->createService()->getMemoryOfChaos($this->createAccount());

        $this->assertSame(36, $data->getStars());
        $this->assertSame(1, $data->getExtraStars());
        $this->assertSame(3, (int) $data->getBattleNum());
        $this->assertTrue($data->getHasData());
        $this->assertCount(2, $data->getPeriods());
        $this->assertSame('2026-12-14 04:00:00', $data->getPeriods()[0]->getEndTime()?->format('Y-m-d H:i:s'));
        $this->assertCount(12, $data->getFloors());
        $this->assertSame('Example Memory (XII)', $data->getFloors()[0]->getLabel());
        $this->assertTrue($data->getFloors()[0]->isTierce());
        $this->assertTrue($data->getFloors()[4]->isFast());
        $this->assertTrue($data->isPlayed());
        $this->assertTrue($data->isMaxed(36));
    }

    public function testDenormalizesApocalypticShadowStarsSentAsStrings(): void
    {
        $data = $this->createService()->getApocalypticShadow($this->createAccount());

        $this->assertSame('4', $data->getFloors()[0]->getStarNum());
        $this->assertSame(3, $data->getFloors()[0]->getBaseStars());
        $this->assertTrue($data->getFloors()[0]->isStarwardPlayed());
        $this->assertSame(12, $data->getStars());
    }

    public function testEndgamesOfAPlayerAtTheMaximum(): void
    {
        $endgames = $this->endgamesByName($this->createService());

        $this->assertSame([
            'Memory of Chaos', 'Memory of Chaos (Starward)',
            'Pure Fiction', 'Pure Fiction (Starward)',
            'Apocalyptic Shadow', 'Apocalyptic Shadow (Starward)',
            'Anomaly Arbitration', 'Anomaly Arbitration (Plight)',
        ], array_keys($endgames));
        foreach ($endgames as $name => $endgame) {
            $this->assertTrue($endgame->isUnlocked(), $name);
            $this->assertNotNull($endgame->getEndTime(), $name);
            if (!str_contains($name, 'Plight')) {
                $this->assertTrue($endgame->isPlayed(), $name);
                $this->assertTrue($endgame->isMaxed(), $name);
            }
        }

        // The Plight of the fixture was not attempted: hard_mode false
        $this->assertFalse($endgames['Anomaly Arbitration (Plight)']->isPlayed());
        $this->assertSame('starward_reminder', $endgames['Memory of Chaos (Starward)']->getVariant());
        $this->assertSame('hard_reminder', $endgames['Anomaly Arbitration (Plight)']->getVariant());

        // One format everywhere: the star last, the full stars of a stage never listed
        $this->assertSame(['36/36 ★'], $endgames['Memory of Chaos']->getProgressLines());
        $this->assertSame(['Prismatic 1/1 ★'], $endgames['Memory of Chaos (Starward)']->getProgressLines());
        $this->assertSame(['12/12 ★'], $endgames['Pure Fiction']->getProgressLines());
        $this->assertSame(['12/12 ★'], $endgames['Apocalyptic Shadow']->getProgressLines());
        $this->assertSame(['Knights 9/9 ★', 'King 3/3 ★'], $endgames['Anomaly Arbitration']->getProgressLines());
    }

    public function testEndsAreReadInTheServerTimeOfTheProfile(): void
    {
        $endgames = $this->endgamesByName($this->createService());

        // EU = UTC+01:00: 04:00 server time is 03:00 UTC; Anomaly Arbitration ends at 23:00 server time
        $this->assertSame('2026-12-14T03:00:00+00:00', $endgames['Memory of Chaos']->getEndTime()->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertSame('2026-11-30T03:00:00+00:00', $endgames['Pure Fiction']->getEndTime()->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertSame('2026-12-28T03:00:00+00:00', $endgames['Apocalyptic Shadow']->getEndTime()->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertSame('2026-12-22T22:00:00+00:00', $endgames['Anomaly Arbitration']->getEndTime()->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        // The variants of a mode share its period
        $this->assertEquals($endgames['Pure Fiction']->getEndTime(), $endgames['Pure Fiction (Starward)']->getEndTime());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function regionProvider(): iterable
    {
        yield 'Europe' => ['prod_official_eur', '2026-11-30T03:00:00+00:00'];
        yield 'America' => ['prod_official_usa', '2026-11-30T09:00:00+00:00'];
        yield 'Asia' => ['prod_official_asia', '2026-11-29T20:00:00+00:00'];
    }

    #[DataProvider('regionProvider')]
    public function testEndIsReadInTheServerTimeOfTheRegion(string $region, string $expectedUtc): void
    {
        // The period is picked by comparing instants: one that is current in every region (the fixture's Pure
        // Fiction runs for six weeks)
        $service = $this->createService(now: '2026-11-25 15:00:00');

        // The wall-clock objects are parsed in the PHP default timezone: it must not matter
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $end = $this->endgamesByName($service, $region)['Pure Fiction']->getEndTime();
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $this->assertSame($expectedUtc, $end->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    public function testPartialClearsListOnlyTheStagesTheResponseHolds(): void
    {
        $floors = [
            HonkaiStarRailEndgameFixture::floor('Example Memory (VI)', 2),
            HonkaiStarRailEndgameFixture::floor('Example Memory (V)', 3, fast: true),
            HonkaiStarRailEndgameFixture::floor('Example Memory (IV)', 0),
            HonkaiStarRailEndgameFixture::floor('Example Memory (III)', 3, fast: true),
        ];
        $service = $this->createService(['challenge' => HonkaiStarRailEndgameFixture::memoryOfChaos($floors, 8, 0, 1)]);

        $endgames = $this->endgamesByName($service);

        $memory = $endgames['Memory of Chaos'];
        $this->assertTrue($memory->isPlayed());
        $this->assertFalse($memory->isMaxed());
        // The stages above (VII to XII) are not in the response: no line for them
        $this->assertSame(['8/36 ★', '↳ Example Memory (VI): 2/3 ★', '↳ Example Memory (IV): 0/3 ★'], $memory->getProgressLines());
        // Starward Mode was not played
        $this->assertFalse($endgames['Memory of Chaos (Starward)']->isPlayed());
        $this->assertSame(['Prismatic 0/1 ★'], $endgames['Memory of Chaos (Starward)']->getProgressLines());
    }

    public function testStarwardPlayedWithoutThePrismaticStar(): void
    {
        $floors = [
            HonkaiStarRailEndgameFixture::floor('Example Fiction (IV)Starward Mode', 3, 0, tierce: true, mode: HonkaiStarRailEndgameFixture::MODE_PURE_FICTION),
            HonkaiStarRailEndgameFixture::floor('Example Fiction (III)', 3, mode: HonkaiStarRailEndgameFixture::MODE_PURE_FICTION),
        ];
        $service = $this->createService(['challenge_story' => HonkaiStarRailEndgameFixture::pureFiction($floors, 12, 0, 2)]);

        $starward = $this->endgamesByName($service)['Pure Fiction (Starward)'];

        $this->assertTrue($starward->isPlayed());
        $this->assertFalse($starward->isMaxed());
        $this->assertSame(['Prismatic 0/1 ★'], $starward->getProgressLines());
    }

    public function testStageOfAnApocalypticShadowBelowThreeStarsIsListedWithItsBaseStars(): void
    {
        $floors = [
            HonkaiStarRailEndgameFixture::floor('Example Shadow: Difficulty 4Starward Mode', 3, 1, tierce: true, stringStars: true, mode: HonkaiStarRailEndgameFixture::MODE_APOCALYPTIC_SHADOW),
            HonkaiStarRailEndgameFixture::floor('Example Shadow: Difficulty 3', 3, stringStars: true, mode: HonkaiStarRailEndgameFixture::MODE_APOCALYPTIC_SHADOW),
        ];
        $service = $this->createService(['challenge_boss' => HonkaiStarRailEndgameFixture::apocalypticShadow($floors, 8, 1, 2)]);

        $shadow = $this->endgamesByName($service)['Apocalyptic Shadow'];

        // 3 stars with the Prismatic Star is 2 base stars; the label has no Starward suffix
        $this->assertSame(['8/12 ★', '↳ Example Shadow: Difficulty 4: 2/3 ★'], $shadow->getProgressLines());
    }

    public function testNotPlayedShape(): void
    {
        $service = $this->createService([
            'challenge' => HonkaiStarRailEndgameFixture::memoryOfChaosNotPlayed(),
            'challenge_story' => HonkaiStarRailEndgameFixture::challenge([
                HonkaiStarRailEndgameFixture::group(9021, HonkaiStarRailEndgameFixture::PURE_FICTION_BEGIN, HonkaiStarRailEndgameFixture::PURE_FICTION_END),
            ]),
            'challenge_boss' => HonkaiStarRailEndgameFixture::challenge([
                HonkaiStarRailEndgameFixture::group(9031, HonkaiStarRailEndgameFixture::APOCALYPTIC_SHADOW_BEGIN, HonkaiStarRailEndgameFixture::APOCALYPTIC_SHADOW_END, 'New'),
            ]),
            'challenge_peak' => HonkaiStarRailEndgameFixture::anomalyArbitration(0, null, played: false),
        ]);

        $endgames = $this->endgamesByName($service);

        foreach ($endgames as $name => $endgame) {
            $this->assertFalse($endgame->isPlayed(), $name);
            $this->assertFalse($endgame->isMaxed(), $name);
            // The end of the period is there all the same
            $this->assertNotNull($endgame->getEndTime(), $name);
        }
    }

    public function testAnomalyArbitrationBelowTheMaximum(): void
    {
        $service = $this->createService(['challenge_peak' => HonkaiStarRailEndgameFixture::anomalyArbitration(9, 2)]);

        $endgames = $this->endgamesByName($service);

        $this->assertTrue($endgames['Anomaly Arbitration']->isPlayed());
        $this->assertFalse($endgames['Anomaly Arbitration']->isMaxed());
        $this->assertSame(['Knights 9/9 ★', 'King 2/3 ★'], $endgames['Anomaly Arbitration']->getProgressLines());
        $this->assertFalse($endgames['Anomaly Arbitration (Plight)']->isPlayed());
        // Plight not cleared: no "Cleared" line
        $this->assertSame([], $endgames['Anomaly Arbitration (Plight)']->getProgressLines());
    }

    public function testPlightClearedIsPlayedAndMaximumWithoutStars(): void
    {
        $service = $this->createService(['challenge_peak' => HonkaiStarRailEndgameFixture::anomalyArbitration(7, 2, plight: true)]);

        $endgames = $this->endgamesByName($service);

        $plight = $endgames['Anomaly Arbitration (Plight)'];
        $this->assertTrue($plight->isPlayed());
        $this->assertTrue($plight->isMaxed());
        // No stars line for Plight
        $this->assertSame(['Cleared'], $plight->getProgressLines());
        // The normal variant counts it as the maximum, whatever the stars
        $this->assertTrue($endgames['Anomaly Arbitration']->isMaxed());
        $this->assertSame(['Knights 7/9 ★', 'King 2/3 ★'], $endgames['Anomaly Arbitration']->getProgressLines());
    }

    public function testPeriodIsPickedByDateNotByStatus(): void
    {
        // The first group already ended, the second one is running: the order and the status are not trusted
        $groups = [
            HonkaiStarRailEndgameFixture::group(2026, ['year' => 2026, 'month' => 9, 'day' => 14, 'hour' => 4, 'minute' => 0], HonkaiStarRailEndgameFixture::PURE_FICTION_BEGIN, 'Running'),
            HonkaiStarRailEndgameFixture::group(9021, HonkaiStarRailEndgameFixture::PURE_FICTION_BEGIN, HonkaiStarRailEndgameFixture::PURE_FICTION_END, 'End'),
        ];
        $service = $this->createService(['challenge_story' => HonkaiStarRailEndgameFixture::challenge($groups)]);

        $end = $this->endgamesByName($service)['Pure Fiction']->getEndTime();

        $this->assertSame('2026-11-30T03:00:00+00:00', $end->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    public function testPeriodBoundaries(): void
    {
        // Pure Fiction begins 2026-10-19 04:00 and ends 2026-11-30 04:00 server time (UTC+01:00, whatever the clock's zone): the begin counts, the end does not
        foreach ([
            ['2026-10-19 03:59:59+01:00', false],
            ['2026-10-19 04:00:00+01:00', true],
            ['2026-11-30 03:59:59+01:00', true],
            ['2026-11-30 04:00:00+01:00', false],
        ] as [$now, $expected]) {
            $service = $this->createService(['challenge_story' => HonkaiStarRailEndgameFixture::challenge([
                HonkaiStarRailEndgameFixture::group(9021, HonkaiStarRailEndgameFixture::PURE_FICTION_BEGIN, HonkaiStarRailEndgameFixture::PURE_FICTION_END),
            ])], now: $now);
            $end = $this->endgamesByName($service)['Pure Fiction']->getEndTime();

            $this->assertSame($expected, $end !== null, $now);
        }
    }

    public function testNoCurrentPeriodMeansAnUnknownEnd(): void
    {
        $service = $this->createService([
            // No group at all, and groups with empty dates
            'challenge' => HonkaiStarRailEndgameFixture::challenge([]),
            'challenge_story' => HonkaiStarRailEndgameFixture::challenge([
                HonkaiStarRailEndgameFixture::group(9021, HonkaiStarRailEndgameFixture::EMPTY_DATE, HonkaiStarRailEndgameFixture::EMPTY_DATE),
            ]),
            'challenge_boss' => HonkaiStarRailEndgameFixture::challenge([
                ['schedule_id' => 9031, 'begin_time' => null, 'end_time' => null, 'status' => 'New'],
            ]),
            // The hour between two runs: the one that ended at 23:00 and the one that starts at 00:00
            'challenge_peak' => HonkaiStarRailEndgameFixture::anomalyArbitration(),
        ], now: '2026-12-22 23:30:00');

        $endgames = $this->endgamesByName($service);

        foreach ($endgames as $name => $endgame) {
            $this->assertNull($endgame->getEndTime(), $name);
        }
    }

    public function testRecordOfAnotherRunIsNotTheCurrentOne(): void
    {
        $body = HonkaiStarRailEndgameFixture::anomalyArbitration(9, 3);
        // A past run listed first, with the maximum; the current one, not played, second
        array_unshift($body['data']['challenge_peak_records'], HonkaiStarRailEndgameFixture::anomalyArbitrationRecord(
            9,
            3,
            begin: ['year' => 2026, 'month' => 9, 'day' => 1, 'hour' => 0, 'minute' => 0],
            end: ['year' => 2026, 'month' => 10, 'day' => 10, 'hour' => 23, 'minute' => 0],
        ));
        $body['data']['challenge_peak_records'][1] = HonkaiStarRailEndgameFixture::anomalyArbitrationRecord(0, null, played: false);
        $service = $this->createService(['challenge_peak' => $body]);

        $endgame = $this->endgamesByName($service)['Anomaly Arbitration'];

        $this->assertFalse($endgame->isPlayed());
        $this->assertSame(['Knights 0/9 ★', 'King 0/3 ★'], $endgame->getProgressLines());
        $this->assertSame('2026-12-22T22:00:00+00:00', $endgame->getEndTime()->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    public function testFetchEndgameThrowsWhenTheRequestFails(): void
    {
        $service = $this->createService(['challenge' => self::errorBody(), 'challenge_peak' => self::errorBody()]);
        $definitions = HonkaiStarRailService::getEndgameDefinitions();

        try {
            $service->fetchEndgame($definitions[0], $this->createAccount());
            $this->fail('Memory of Chaos should throw');
        } catch (RetrieveMemoryOfChaosDataException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(RetrieveAnomalyArbitrationDataException::class);
        $service->fetchEndgame($definitions[3], $this->createAccount());
    }

    /**
     * Whole path of the scheduled task: HoYoLAB responses -> DTOs -> endgames -> notification.
     */
    public function testReminderHandlerWithHoyolabResponses(): void
    {
        $service = $this->createService([
            'challenge' => HonkaiStarRailEndgameFixture::memoryOfChaos(),
            'challenge_story' => HonkaiStarRailEndgameFixture::pureFiction([
                HonkaiStarRailEndgameFixture::floor('Example Fiction (II)', 2, mode: HonkaiStarRailEndgameFixture::MODE_PURE_FICTION),
                HonkaiStarRailEndgameFixture::floor('Example Fiction (I)', 3, fast: true, mode: HonkaiStarRailEndgameFixture::MODE_PURE_FICTION),
            ], 5, 0, 1),
            'challenge_boss' => HonkaiStarRailEndgameFixture::challenge(
                [HonkaiStarRailEndgameFixture::group(9031, HonkaiStarRailEndgameFixture::APOCALYPTIC_SHADOW_BEGIN, HonkaiStarRailEndgameFixture::APOCALYPTIC_SHADOW_END, 'New')]
            ),
        ]);

        // Default settings: Anomaly Arbitration is off, Pure Fiction ends within a week and was played, the others
        // end later: nothing to remind
        $sent = [];
        $this->createHandler($service, null, $sent)(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $sent);
        $this->assertNotContains('challenge_peak', array_map('basename', array_column($this->requests, 'path')));
        $this->assertCount(3, $this->requests);

        // Full clear on Pure Fiction, and on its Starward Mode
        $this->createHandler($service, ['pure_fiction' => ['reminder' => 'full_clear', 'starward_reminder' => 'full_clear']], $sent)(new EndgamesReminderProfileMessage(1));
        $this->assertCount(1, $sent);
        $this->assertSame('Honkai: Star Rail - Endgames Reminder', $sent[0]->getTitle());
        $this->assertSame([
            ['Pure Fiction', "5/12 ★\n↳ Example Fiction (II): 2/3 ★", '⚔️'],
            // Not played in Starward Mode
            ['Pure Fiction (Starward)', 'Not played yet', '⚔️'],
            ['Ends in', '4 days, 13 hr', '🕒'],
        ], self::endgameFields($sent[0]));
        foreach (array_slice($sent[0]->getFields(), 3) as $field) {
            $this->assertTrue($field->isInline());
        }
    }

    public function testNotPlayedModeWithinTheWindowIsReminded(): void
    {
        $service = $this->createService(['challenge_story' => HonkaiStarRailEndgameFixture::challenge([
            HonkaiStarRailEndgameFixture::group(9021, HonkaiStarRailEndgameFixture::PURE_FICTION_BEGIN, HonkaiStarRailEndgameFixture::PURE_FICTION_END),
        ])]);

        $sent = [];
        $this->createHandler($service, null, $sent)(new EndgamesReminderProfileMessage(1));

        $this->assertCount(1, $sent);
        $this->assertSame(['Pure Fiction', 'Not played yet', '⚔️'], self::endgameFields($sent[0])[0]);
        $this->assertSame(['Ends in', '4 days, 13 hr', '🕒'], self::endgameFields($sent[0])[2]);
    }

    public function testNoCurrentPeriodSendsNothingAndLogsIt(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->logicalAnd($this->stringContains('period unknown'), $this->stringContains('no reminder')));
        $service = $this->createService(['challenge_story' => HonkaiStarRailEndgameFixture::challenge([])]);

        $sent = [];
        $this->createHandler($service, null, $sent, logger: $logger)(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $sent);
    }

    public function testUnreadableModeDoesNotHideTheOthers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('Memory of Chaos'),
                    $this->stringContains(RetrieveMemoryOfChaosDataException::class),
                    $this->stringContains('10102'),
                    $this->logicalNot($this->stringContains('Data is not public'))
                )
            );
        $service = $this->createService(['challenge' => self::errorBody(), 'challenge_story' => HonkaiStarRailEndgameFixture::challenge([
            HonkaiStarRailEndgameFixture::group(9021, HonkaiStarRailEndgameFixture::PURE_FICTION_BEGIN, HonkaiStarRailEndgameFixture::PURE_FICTION_END),
        ])], $logger);

        $sent = [];
        $this->createHandler($service, null, $sent, logger: $logger)(new EndgamesReminderProfileMessage(1));

        $this->assertCount(1, $sent);
        $this->assertSame('Pure Fiction', self::endgameFields($sent[0])[0][0]);
    }

    public function testUnreadableShapeDoesNotHideTheOthers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Memory of Chaos'));
        $unreadable = HonkaiStarRailEndgameFixture::challenge([]);
        // Not a date object nor null: the denormalization throws
        $unreadable['data']['groups'] = [['begin_time' => 'not a date', 'end_time' => 'not a date']];
        $service = $this->createService(['challenge' => $unreadable, 'challenge_story' => HonkaiStarRailEndgameFixture::challenge([
            HonkaiStarRailEndgameFixture::group(9021, HonkaiStarRailEndgameFixture::PURE_FICTION_BEGIN, HonkaiStarRailEndgameFixture::PURE_FICTION_END),
        ])], $logger);

        $sent = [];
        $this->createHandler($service, null, $sent, logger: $logger)(new EndgamesReminderProfileMessage(1));

        $this->assertCount(1, $sent);
        $this->assertSame('Pure Fiction', self::endgameFields($sent[0])[0][0]);
    }

    public function testWhenEveryModeFailsTheFirstErrorIsThrown(): void
    {
        $service = $this->createService(['challenge' => self::errorBody(), 'challenge_story' => self::errorBody(), 'challenge_boss' => self::errorBody()]);

        $sent = [];
        $this->expectException(RetrieveMemoryOfChaosDataException::class);
        $this->createHandler($service, null, $sent)(new EndgamesReminderProfileMessage(1));
    }

    public function testApocalypticShadowAloneFailingIsOnlyLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('Apocalyptic Shadow'),
                $this->stringContains(RetrieveApocalypticShadowDataException::class)
            ));
        $service = $this->createService(['challenge_boss' => self::errorBody(), 'challenge_story' => HonkaiStarRailEndgameFixture::challenge([
            HonkaiStarRailEndgameFixture::group(9021, HonkaiStarRailEndgameFixture::PURE_FICTION_BEGIN, HonkaiStarRailEndgameFixture::PURE_FICTION_END),
        ])], $logger);

        $sent = [];
        $this->createHandler($service, null, $sent, logger: $logger)(new EndgamesReminderProfileMessage(1));
        $this->assertCount(1, $sent);

        $this->expectException(RetrieveApocalypticShadowDataException::class);
        $service->fetchEndgame(HonkaiStarRailService::getEndgameDefinitions()[2], $this->createAccount());
    }
}
