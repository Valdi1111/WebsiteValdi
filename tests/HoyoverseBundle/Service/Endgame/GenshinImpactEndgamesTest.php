<?php

namespace App\Tests\HoyoverseBundle\Service\Endgame;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Endgame\EndgameLevel;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameTarget;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Presenter\EndgamesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveTheaterLobbyDataException;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\EndgamesReminderProfileHandler;
use App\HoyoverseBundle\Model\Endgame\SpiralAbyssFloor;
use App\HoyoverseBundle\Model\Endgame\TheaterLobbyType;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
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
 * GenshinImpactEndgamesTrait against HoYoLAB-shaped responses, denormalized by the application serializer.
 */
class GenshinImpactEndgamesTest extends KernelTestCase
{
    /** Inside the Stygian Onslaught and Imaginarium Theater windows (4 and 5 days before their ends), not the Abyss one */
    private const string NOW = '2026-03-27 15:00:00';

    /** @var list<array{path: string, query: array<string, string>, headers: array<string, mixed>}> */
    private array $requests = [];

    private function createService(
        array $spiralAbyssBody,
        array $theaterBody,
        array $stygianBody,
        string $now = self::NOW,
        ?LoggerInterface $logger = null,
    ): GenshinImpactService {
        self::bootKernel();

        $service = new GenshinImpactService($logger ?? new NullLogger());
        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setHoyolabUtils(new HoyolabUtilsService());
        $service->setEndgamesClock(new MockClock($now, 'Europe/Paris'));
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url, array $options) use ($spiralAbyssBody, $theaterBody, $stygianBody): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->requests[] = ['path' => $path, 'query' => $query, 'headers' => $options['normalized_headers'] ?? []];

            $body = match (basename($path)) {
                'spiralAbyss' => $spiralAbyssBody,
                'role_combat' => $theaterBody,
                'hard_challenge' => $stygianBody,
                default => ['retcode' => -1, 'message' => 'unexpected endpoint'],
            };

            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        }));

        return $service;
    }

    /**
     * The service with the default responses, $bodies replacing some of them.
     *
     * @param array{spiral_abyss?: array, imaginarium_theater?: array, stygian_onslaught?: array} $bodies
     */
    private function createServiceWith(array $bodies = [], string $now = self::NOW, ?LoggerInterface $logger = null): GenshinImpactService
    {
        return $this->createService(
            $bodies[GenshinImpactService::ENDGAME_SPIRAL_ABYSS] ?? GenshinEndgameFixture::spiralAbyss(),
            $bodies[GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER] ?? GenshinEndgameFixture::theater(),
            $bodies[GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT] ?? GenshinEndgameFixture::stygianOnslaught(),
            $now,
            $logger,
        );
    }

    private function createAccount(): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())
            ->setGameId(GenshinImpactService::GAME_ID)
            ->setGameUid('700000001')
            ->setRegion('os_euro')
            ->setNickname('Traveler');

        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile($profile);
    }

    /**
     * Result of one mode, which has a single variant.
     */
    private function fetch(GenshinImpactService $service, string $mode): ?GameEndgame
    {
        foreach (GenshinImpactService::getEndgameDefinitions() as $definition) {
            if ($definition->key === $mode) {
                $endgames = $service->fetchEndgame($definition, $this->createAccount());
                $this->assertLessThanOrEqual(1, count($endgames));

                return $endgames[0] ?? null;
            }
        }

        $this->fail(sprintf('Mode "%s" not declared', $mode));
    }

    private static function target(string $mode, string $value): EndgameTarget
    {
        foreach (GenshinImpactService::getEndgameDefinitions() as $definition) {
            if ($definition->key === $mode) {
                return $definition->variants[0]->findTarget($value);
            }
        }

        throw new \LogicException(sprintf('Mode "%s" not declared', $mode));
    }

    /**
     * The reminder handler around $service, for an EU profile with the given settings.
     *
     * @param array<string, mixed>|null $settings endgames_settings of the profile
     * @param list<UniversalEmbed>      $sent     notifications sent
     */
    private function createHandler(
        GenshinImpactService $service,
        ?array $settings,
        array &$sent,
        string $now = self::NOW,
        ?LoggerInterface $logger = null,
    ): EndgamesReminderProfileHandler {
        $account = new HoyoverseAccount()->setUser(new User())->setCookie('ltoken_v2=test; ltuid_v2=123;');
        $profile = $this->createAccount()->getGameProfile()
            ->setAccount($account)
            ->setGameName('Genshin Impact')
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

    public function testContainerWiresTheEndgamesClockAndTheReminderWindows(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(ClockInterface::class, static::getContainer()->get(GenshinImpactService::class)->getEndgamesClock());
        $this->assertSame(
            ['spiral_abyss' => ['days_before_end' => 7], 'imaginarium_theater' => ['days_before_end' => 7], 'stygian_onslaught' => ['days_before_end' => 7]],
            static::getContainer()->getParameter('hoyoverse.endgames_reminder')['gi']
        );
    }

    public function testDeclaresTheThreeModesTheirDefaultsAndLevels(): void
    {
        $this->assertSame('gi', GenshinImpactService::getEndgamesConfigKey());

        $declared = [];
        foreach (GenshinImpactService::getEndgameDefinitions() as $definition) {
            $this->assertCount(1, $definition->variants);
            $variant = $definition->variants[0];
            $declared[$definition->key] = [
                $variant->key,
                $variant->name,
                $variant->defaultReminder,
                array_map(static fn (EndgameLevel $level) => [$level->key, $level->name], $variant->levels),
            ];
        }

        $this->assertSame([
            'spiral_abyss' => ['reminder', 'Spiral Abyss', EndgameReminder::NotPlayed, []],
            'imaginarium_theater' => ['reminder', 'Imaginarium Theater', EndgameReminder::NotPlayed, [
                ['easy', 'Easy'], ['normal', 'Normal'], ['hard', 'Hard'], ['visionary', 'Visionary'], ['lunar', 'Lunar'],
            ]],
            'stygian_onslaught' => ['reminder', 'Stygian Onslaught', EndgameReminder::NotPlayed, [
                ['normal', 'Normal'], ['advancing', 'Advancing'], ['hard', 'Hard'], ['menacing', 'Menacing'], ['fearless', 'Fearless'], ['dire', 'Dire'],
            ]],
        ], $declared);
    }

    /**
     * Same calls as ApiGameProfilesController::apiGameProfilesUpdateSettings() and apiGameProfilesIdGet().
     */
    public function testEndgameSettingsWithLevelsAreSavedAndExposed(): void
    {
        self::bootKernel();
        $serializer = static::getContainer()->get('serializer');
        $payload = [
            'endgames_check' => true,
            'endgames_settings' => [
                // A level of another mode: not stored
                'spiral_abyss' => ['reminder' => 'lunar'],
                'imaginarium_theater' => ['reminder' => 'visionary'],
                // Full Clear = the top level
                'stygian_onslaught' => ['reminder' => 'full_clear'],
            ],
        ];

        $genshin = (new HoyoverseGameProfile())->setGameId(GenshinImpactService::GAME_ID);
        $serializer->denormalize($payload, HoyoverseGameProfile::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $genshin]);
        $this->assertTrue($genshin->isEndgamesCheck());
        $this->assertSame(
            ['spiral_abyss' => ['reminder' => 'not_played'], 'imaginarium_theater' => ['reminder' => 'visionary'], 'stygian_onslaught' => ['reminder' => 'dire']],
            $genshin->getEndgamesSettings()
        );

        $json = json_decode($serializer->serialize($genshin, 'json'), true);
        $this->assertSame($genshin->getEndgamesSettings(), $json['endgames_settings']);
        $this->assertSame(['spiral_abyss', 'imaginarium_theater', 'stygian_onslaught'], array_column($json['endgames'], 'key'));
        // No "levels" on a variant without levels
        $this->assertSame([['key' => 'reminder', 'name' => 'Spiral Abyss', 'default' => 'not_played']], $json['endgames'][0]['variants']);
        $this->assertSame([[
            'key' => 'reminder',
            'name' => 'Imaginarium Theater',
            'default' => 'not_played',
            'levels' => [
                ['key' => 'easy', 'name' => 'Easy'],
                ['key' => 'normal', 'name' => 'Normal'],
                ['key' => 'hard', 'name' => 'Hard'],
                ['key' => 'visionary', 'name' => 'Visionary'],
                ['key' => 'lunar', 'name' => 'Lunar'],
            ],
        ]], $json['endgames'][1]['variants']);
        $this->assertSame(['normal', 'advancing', 'hard', 'menacing', 'fearless', 'dire'], array_column($json['endgames'][2]['variants'][0]['levels'], 'key'));
    }

    public function testRequestsTheThreeRecordsWithoutDsHeader(): void
    {
        $service = $this->createServiceWith();

        foreach (GenshinImpactService::getEndgameDefinitions() as $definition) {
            $service->fetchEndgame($definition, $this->createAccount());
        }

        $this->assertSame([
            ['/event/game_record/genshin/api/spiralAbyss', ['role_id' => '700000001', 'server' => 'os_euro', 'schedule_type' => '1']],
            ['/event/game_record/genshin/api/role_combat', ['role_id' => '700000001', 'server' => 'os_euro', 'need_detail' => 'false']],
            ['/event/game_record/genshin/api/hard_challenge', ['role_id' => '700000001', 'server' => 'os_euro']],
        ], array_map(static fn (array $request) => [$request['path'], $request['query']], $this->requests));
        foreach ($this->requests as $request) {
            $this->assertArrayNotHasKey('ds', $request['headers']);
            $this->assertArrayHasKey('cookie', $request['headers']);
        }
    }

    public function testDenormalizesTheResponses(): void
    {
        $service = $this->createServiceWith([
            GenshinImpactService::ENDGAME_SPIRAL_ABYSS => GenshinEndgameFixture::spiralAbyss([[11, 9], [12, 4]], 9, 31),
            GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => GenshinEndgameFixture::theater([4, 7, 0, [1, 1, 1, 0, 1, 1, 1]]),
            GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT => GenshinEndgameFixture::stygianOnslaught([4, 197], [3, 140]),
        ]);

        $abyss = $service->getSpiralAbyss($this->createAccount());
        $this->assertSame(9, $abyss->getTotalBattleTimes());
        $this->assertSame(31, $abyss->getTotalStar());
        $this->assertSame('12-3', $abyss->getMaxFloor());
        $this->assertTrue($abyss->getIsUnlock());
        $this->assertSame(99, $abyss->getScheduleId());
        $this->assertSame((int) GenshinEndgameFixture::SPIRAL_ABYSS_START, $abyss->getStartTime()?->getTimestamp());
        $this->assertSame((int) GenshinEndgameFixture::SPIRAL_ABYSS_END, $abyss->getEndTime()?->getTimestamp());
        $this->assertContainsOnlyInstancesOf(SpiralAbyssFloor::class, $abyss->getFloors());
        $this->assertSame([[11, 9, 9], [12, 4, 9]], array_map(static fn (SpiralAbyssFloor $floor) => [$floor->getIndex(), $floor->getStar(), $floor->getMaxStar()], $abyss->getFloors()));

        $theater = $service->getTheaterLobby($this->createAccount());
        $this->assertTrue($theater->getIsUnlock());
        $this->assertCount(2, $theater->getData());
        $this->assertContainsOnlyInstancesOf(TheaterLobbyType::class, $theater->getData());
        $current = $theater->getCurrentPeriod();
        $this->assertSame($theater->getData()[0], $current);
        $this->assertSame(1, $current->getSchedule()->getScheduleType());
        $this->assertSame(41, $current->getSchedule()->getScheduleId());
        $this->assertSame((int) GenshinEndgameFixture::THEATER_END, $current->getSchedule()->getEndTime()?->getTimestamp());
        $this->assertTrue($current->getHasData());
        $this->assertSame([4, 7, 0, 6, [1, 1, 1, 0, 1, 1, 1]], [
            $current->getStat()->getDifficultyId(),
            $current->getStat()->getMaxRoundId(),
            $current->getStat()->getTarotFinishedCount(),
            $current->getStat()->getMedalNum(),
            $current->getStat()->getMedalRoundList(),
        ]);
        $this->assertSame(2, $theater->getData()[1]->getSchedule()->getScheduleType());

        $stygian = $service->getStygianOnslaught($this->createAccount());
        $this->assertTrue($stygian->getIsUnlock());
        $this->assertCount(2, $stygian->getData());
        $period = $stygian->getData()[0];
        $this->assertSame('9900002', $period->getSchedule()->getScheduleId());
        $this->assertNull($period->getSchedule()->getScheduleType());
        $this->assertSame((int) GenshinEndgameFixture::STYGIAN_END, $period->getSchedule()->getEndTime()?->getTimestamp());
        $this->assertTrue($period->getSingle()->getHasData());
        $this->assertSame([4, 197, 'UI_LeyLineChallenge_Medal_4'], [$period->getSingle()->getBest()->getDifficulty(), $period->getSingle()->getBest()->getSecond(), $period->getSingle()->getBest()->getIcon()]);
        $this->assertSame(3, $period->getMp()->getBest()->getDifficulty());
        $this->assertNull($stygian->getData()[1]->getMp()->getBest());
        $this->assertFalse($stygian->getData()[1]->getMp()->getHasData());
    }

    public function testSpiralAbyssAtTheMaximum(): void
    {
        $abyss = $this->fetch($this->createServiceWith(), GenshinImpactService::ENDGAME_SPIRAL_ABYSS);

        $this->assertTrue($abyss->isUnlocked());
        $this->assertTrue($abyss->isPlayed());
        $this->assertTrue($abyss->isMaxed());
        $this->assertSame((int) GenshinEndgameFixture::SPIRAL_ABYSS_END, $abyss->getEndTime()->getTimestamp());
        $this->assertSame(['36/36 ★'], $abyss->getProgressLines());
        $this->assertFalse($abyss->needsReminder(self::target('spiral_abyss', 'full_clear')));
    }

    public function testSpiralAbyssBelowTheMaximumListsTheFloorsBelowNineStars(): void
    {
        // Floors 9 and 10 skipped (18 stars), floor 11 at 9 stars, floor 12 at 4
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_SPIRAL_ABYSS => GenshinEndgameFixture::spiralAbyss([[11, 9], [12, 4]], 9, 31)]);

        $abyss = $this->fetch($service, GenshinImpactService::ENDGAME_SPIRAL_ABYSS);

        $this->assertTrue($abyss->isPlayed());
        $this->assertFalse($abyss->isMaxed());
        $this->assertSame(['31/36 ★', '↳ Floor 12: 4/9 ★'], $abyss->getProgressLines());
        $this->assertTrue($abyss->needsReminder(self::target('spiral_abyss', 'full_clear')));
        $this->assertFalse($abyss->needsReminder(self::target('spiral_abyss', 'not_played')));
    }

    /**
     * Shape seen live on 2026-11-22 at the start of a period: stars credited for the skipped floors, no battle.
     */
    public function testSpiralAbyssWithOnlySkippedFloorsIsNotPlayed(): void
    {
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_SPIRAL_ABYSS => GenshinEndgameFixture::spiralAbyssWithSkippedFloors()]);

        $abyss = $this->fetch($service, GenshinImpactService::ENDGAME_SPIRAL_ABYSS);

        $this->assertTrue($abyss->isUnlocked());
        $this->assertFalse($abyss->isPlayed());
        $this->assertTrue($abyss->needsReminder(self::target('spiral_abyss', 'not_played')));
        $this->assertNotNull($abyss->getEndTime());
    }

    /**
     * Shape seen live on 2026-11-22 on an account that has not reached it.
     */
    public function testLockedSpiralAbyssIsNeverReminded(): void
    {
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_SPIRAL_ABYSS => GenshinEndgameFixture::spiralAbyssLocked()]);

        $abyss = $this->fetch($service, GenshinImpactService::ENDGAME_SPIRAL_ABYSS);

        $this->assertFalse($abyss->isUnlocked());
        $this->assertFalse($abyss->isPlayed());
        $this->assertFalse($abyss->needsReminder(self::target('spiral_abyss', 'full_clear')));
    }

    /**
     * Shape seen live on 2026-11-22 (previous period): Lunar to act 10 with both Arcana, some stars missing.
     */
    public function testTheaterLunarCompleted(): void
    {
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => GenshinEndgameFixture::theater([5, 10, 2, [1, 0, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1]])]);

        $theater = $this->fetch($service, GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER);

        $this->assertTrue($theater->isPlayed());
        $this->assertSame('lunar', $theater->getCompletedLevel());
        // The stars decide nothing: Lunar completed is the maximum
        $this->assertTrue($theater->isMaxed());
        $this->assertSame((int) GenshinEndgameFixture::THEATER_END, $theater->getEndTime()->getTimestamp());
        $this->assertSame(['Lunar: Act 10/10, Arcana 2/2', '10/12 ★'], $theater->getProgressLines());
        $this->assertFalse($theater->needsReminder(self::target('imaginarium_theater', 'full_clear')));
    }

    public function testTheaterStoppedBeforeTheEndCountsForAnEasierDifficulty(): void
    {
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => GenshinEndgameFixture::theater([4, 7, 0, [1, 1, 1, 0, 1, 1, 1]])]);

        $theater = $this->fetch($service, GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER);

        $this->assertTrue($theater->isPlayed());
        $this->assertSame('normal', $theater->getCompletedLevel());
        $this->assertFalse($theater->isMaxed());
        $this->assertSame(['Visionary: Act 7/10', '6/10 ★'], $theater->getProgressLines());
        $this->assertTrue($theater->needsReminder(self::target('imaginarium_theater', 'visionary')));
        $this->assertTrue($theater->needsReminder(self::target('imaginarium_theater', 'hard')));
        $this->assertFalse($theater->needsReminder(self::target('imaginarium_theater', 'normal')));
        $this->assertFalse($theater->needsReminder(self::target('imaginarium_theater', 'not_played')));
    }

    /**
     * Shape seen live on 2026-11-22 for the current period: has_data false and a zeroed stat.
     */
    public function testTheaterNotPlayed(): void
    {
        $theater = $this->fetch($this->createServiceWith(), GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER);

        $this->assertTrue($theater->isUnlocked());
        $this->assertFalse($theater->isPlayed());
        $this->assertNull($theater->getCompletedLevel());
        $this->assertSame([], $theater->getProgressLines());
        $this->assertSame((int) GenshinEndgameFixture::THEATER_END, $theater->getEndTime()->getTimestamp());
        $this->assertTrue($theater->needsReminder(self::target('imaginarium_theater', 'not_played')));
    }

    public function testTheaterWithoutACurrentPeriodHasNoEnd(): void
    {
        $body = GenshinEndgameFixture::theater([5, 10, 2, [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]]);
        // Only the previous period
        array_shift($body['data']['data']);

        $theater = $this->fetch($this->createServiceWith([GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => $body]), GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER);

        $this->assertFalse($theater->isPlayed());
        $this->assertNull($theater->getEndTime());
    }

    public function testStygianOnslaughtBestOfTheCurrentPeriod(): void
    {
        $stygian = $this->fetch($this->createServiceWith(), GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT);

        $this->assertTrue($stygian->isPlayed());
        $this->assertSame('menacing', $stygian->getCompletedLevel());
        $this->assertFalse($stygian->isMaxed());
        $this->assertSame((int) GenshinEndgameFixture::STYGIAN_END, $stygian->getEndTime()->getTimestamp());
        $this->assertSame(['Best: Menacing (3:17)'], $stygian->getProgressLines());
        // All the rewards come at Hard: a player who aims there is not reminded any more
        $this->assertFalse($stygian->needsReminder(self::target('stygian_onslaught', 'hard')));
        $this->assertTrue($stygian->needsReminder(self::target('stygian_onslaught', 'fearless')));
        $this->assertTrue($stygian->needsReminder(self::target('stygian_onslaught', 'full_clear')));
    }

    public function testStygianOnslaughtSameDifficultySoloAndCoopShowsTheFasterRun(): void
    {
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT => GenshinEndgameFixture::stygianOnslaught([6, 425], [6, 397])]);

        $stygian = $this->fetch($service, GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT);

        $this->assertSame('dire', $stygian->getCompletedLevel());
        $this->assertTrue($stygian->isMaxed());
        $this->assertSame(['Best: Dire (6:37)'], $stygian->getProgressLines());
    }

    public function testStygianOnslaughtRecordWithoutABestIsPlayed(): void
    {
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT => GenshinEndgameFixture::stygianOnslaught([])]);

        $stygian = $this->fetch($service, GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT);

        $this->assertTrue($stygian->isPlayed());
        $this->assertNull($stygian->getCompletedLevel());
        $this->assertSame(['No difficulty completed yet'], $stygian->getProgressLines());
        // Played: not reminded with Not Played, reminded up to a level
        $this->assertFalse($stygian->needsReminder(self::target('stygian_onslaught', 'not_played')));
        $this->assertTrue($stygian->needsReminder(self::target('stygian_onslaught', 'normal')));
    }

    /**
     * Shape seen live on 2026-11-22 on an account without any record.
     */
    public function testStygianOnslaughtNotPlayed(): void
    {
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT => GenshinEndgameFixture::stygianOnslaught(null)]);

        $stygian = $this->fetch($service, GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT);

        $this->assertTrue($stygian->isUnlocked());
        $this->assertFalse($stygian->isPlayed());
        $this->assertSame([], $stygian->getProgressLines());
        $this->assertTrue($stygian->needsReminder(self::target('stygian_onslaught', 'not_played')));
    }

    /**
     * The period is the one containing now: data[] also lists the previous one, and neither between two periods.
     */
    public function testStygianOnslaughtPeriodIsChosenByDate(): void
    {
        $previous = $this->fetch($this->createServiceWith(now: '2026-02-01 15:00:00'), GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT);
        $this->assertSame((int) GenshinEndgameFixture::STYGIAN_PREVIOUS_END, $previous->getEndTime()->getTimestamp());
        $this->assertSame(['Best: Fearless (4:01)'], $previous->getProgressLines());

        $gap = $this->fetch($this->createServiceWith(now: '2026-02-20 15:00:00'), GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT);
        $this->assertTrue($gap->isUnlocked());
        $this->assertFalse($gap->isPlayed());
        $this->assertNull($gap->getEndTime());
    }

    /**
     * @return iterable<string, array{0: string, 1: array, 2: string}>
     */
    public static function unknownDifficultyProvider(): iterable
    {
        yield 'Imaginarium Theater above Lunar' => [
            GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER,
            GenshinEndgameFixture::theater([6, 10, 2, [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]]),
            'Unknown Imaginarium Theater difficulty 6',
        ];
        yield 'Stygian Onslaught above Dire' => [
            GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT,
            GenshinEndgameFixture::stygianOnslaught([7, 300], [5, 200]),
            'Unknown Stygian Onslaught difficulty 7',
        ];
    }

    #[DataProvider('unknownDifficultyProvider')]
    public function testUnknownDifficultyIsSkippedAndLogged(string $mode, array $body, string $message): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains($message));

        $this->assertNull($this->fetch($this->createServiceWith([$mode => $body], logger: $logger), $mode));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function missingDataProvider(): iterable
    {
        yield 'no data at all' => [null];
        yield 'empty data' => [[]];
        yield 'lists null' => [['data' => null, 'floors' => null, 'is_unlock' => true]];
    }

    #[DataProvider('missingDataProvider')]
    public function testMissingDataIsNotPlayedAndHasNoEnd(mixed $data): void
    {
        $body = ['retcode' => 0, 'message' => 'OK', 'data' => $data];
        $service = $this->createService($body, $body, $body);

        foreach (GenshinImpactService::getEndgameDefinitions() as $definition) {
            $endgame = $this->fetch($service, $definition->key);
            $this->assertTrue($endgame->isUnlocked(), $definition->name);
            $this->assertFalse($endgame->isPlayed(), $definition->name);
            $this->assertNull($endgame->getEndTime(), $definition->name);
        }
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function emptyEpochProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'zero' => ['0'];
        yield 'null' => [null];
    }

    #[DataProvider('emptyEpochProvider')]
    public function testEmptyEpochsAreAnAbsentEnd(mixed $epoch): void
    {
        $abyss = GenshinEndgameFixture::spiralAbyss();
        $abyss['data']['start_time'] = $epoch;
        $abyss['data']['end_time'] = $epoch;
        $theater = GenshinEndgameFixture::theater();
        $theater['data']['data'][0]['schedule']['end_time'] = $epoch;

        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_SPIRAL_ABYSS => $abyss, GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => $theater]);

        $this->assertNull($this->fetch($service, GenshinImpactService::ENDGAME_SPIRAL_ABYSS)->getEndTime());
        $this->assertNull($this->fetch($service, GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER)->getEndTime());
    }

    public function testFetchEndgameThrowsWhenTheRequestFails(): void
    {
        $service = $this->createServiceWith([GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => self::errorBody()]);

        $this->expectException(RetrieveTheaterLobbyDataException::class);
        $this->fetch($service, GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER);
    }

    /**
     * Whole path of the scheduled task: HoYoLAB responses -> DTOs -> endgames -> notification. Theater and Stygian
     * end within 7 days, the Abyss does not.
     */
    public function testReminderHandlerUpToTheChosenDifficulty(): void
    {
        $bodies = [
            GenshinImpactService::ENDGAME_SPIRAL_ABYSS => GenshinEndgameFixture::spiralAbyss([[11, 9], [12, 4]], 9, 31),
            GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => GenshinEndgameFixture::theater([4, 7, 0, [1, 1, 1, 0, 1, 1, 1]]),
        ];

        // Default settings: Theater and Stygian played, the Abyss outside its window
        $sent = [];
        $this->createHandler($this->createServiceWith($bodies), null, $sent)(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $sent);

        // Theater up to Visionary (Normal completed), Stygian up to Hard (Menacing completed)
        $this->createHandler($this->createServiceWith($bodies), [
            'spiral_abyss' => ['reminder' => 'full_clear'],
            'imaginarium_theater' => ['reminder' => 'visionary'],
            'stygian_onslaught' => ['reminder' => 'hard'],
        ], $sent)(new EndgamesReminderProfileMessage(1));
        $this->assertCount(1, $sent);
        $this->assertSame('Genshin Impact - Endgames Reminder', $sent[0]->getTitle());
        $this->assertSame([
            ['Imaginarium Theater', "Visionary: Act 7/10\n6/10 ★", '⚔️'],
            [EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, null],
            ['Ends in', '4 days, 12 hr', '🕒'],
        ], self::endgameFields($sent[0]));

        // Stygian up to Dire as well
        $sent = [];
        $this->createHandler($this->createServiceWith($bodies), [
            'imaginarium_theater' => ['reminder' => 'visionary'],
            'stygian_onslaught' => ['reminder' => 'dire'],
        ], $sent)(new EndgamesReminderProfileMessage(1));
        $this->assertSame([
            ['Imaginarium Theater', "Visionary: Act 7/10\n6/10 ★", '⚔️'],
            [EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, null],
            ['Ends in', '4 days, 12 hr', '🕒'],
            ['Stygian Onslaught', 'Best: Menacing (3:17)', '⚔️'],
            [EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, null],
            ['Ends in', '3 days, 12 hr', '🕒'],
        ], self::endgameFields($sent[0]));
    }

    public function testReminderHandlerWithTheChosenDifficultyCompleted(): void
    {
        $bodies = [GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => GenshinEndgameFixture::theater([4, 10, 0, [1, 1, 1, 1, 1, 1, 1, 1, 0, 1]])];

        $sent = [];
        $this->createHandler($this->createServiceWith($bodies), [
            'imaginarium_theater' => ['reminder' => 'visionary'],
            'stygian_onslaught' => ['reminder' => 'menacing'],
        ], $sent)(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $sent);
    }

    public function testReminderHandlerForModesNotPlayed(): void
    {
        $bodies = [
            GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => GenshinEndgameFixture::theater(),
            GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT => GenshinEndgameFixture::stygianOnslaught(null),
        ];

        $sent = [];
        $this->createHandler($this->createServiceWith($bodies), null, $sent)(new EndgamesReminderProfileMessage(1));

        $this->assertSame([
            ['Imaginarium Theater', 'Not played yet', '⚔️'],
            [EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, null],
            ['Ends in', '4 days, 12 hr', '🕒'],
            ['Stygian Onslaught', 'Not played yet', '⚔️'],
            [EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, null],
            ['Ends in', '3 days, 12 hr', '🕒'],
        ], self::endgameFields($sent[0]));
    }

    public function testReminderHandlerForTheSpiralAbyss(): void
    {
        $bodies = [GenshinImpactService::ENDGAME_SPIRAL_ABYSS => GenshinEndgameFixture::spiralAbyss([[11, 9], [12, 4]], 9, 31)];

        $sent = [];
        // 3 days and 13 hours before the end of the Abyss; Theater and Stygian over
        $this->createHandler($this->createServiceWith($bodies, '2026-04-12 15:00:00'), ['spiral_abyss' => ['reminder' => 'full_clear']], $sent, '2026-04-12 15:00:00')(new EndgamesReminderProfileMessage(1));

        $this->assertSame([
            ['Spiral Abyss', "31/36 ★\n↳ Floor 12: 4/9 ★", '⚔️'],
            [EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, null],
            ['Ends in', '3 days, 13 hr', '🕒'],
        ], self::endgameFields($sent[0]));
    }

    /**
     * Between two Stygian Onslaught periods: no end, logged by the handler at info, no reminder.
     */
    public function testReminderHandlerBetweenTwoStygianOnslaughtPeriods(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('End of the "Stygian Onslaught" period unknown'));

        $now = '2026-02-20 15:00:00';
        $sent = [];
        $this->createHandler($this->createServiceWith([], $now), ['stygian_onslaught' => ['reminder' => 'dire']], $sent, $now, $logger)(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $sent);
    }

    public function testUnreadableModeDoesNotHideTheOthers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('Imaginarium Theater'),
                $this->stringContains(RetrieveTheaterLobbyDataException::class),
                $this->logicalNot($this->stringContains('Data is not public'))
            ));
        $service = $this->createServiceWith([
            GenshinImpactService::ENDGAME_IMAGINARIUM_THEATER => self::errorBody(),
            GenshinImpactService::ENDGAME_STYGIAN_ONSLAUGHT => GenshinEndgameFixture::stygianOnslaught(null),
        ]);

        $sent = [];
        $this->createHandler($service, null, $sent, logger: $logger)(new EndgamesReminderProfileMessage(1));

        $this->assertCount(1, $sent);
        $this->assertSame(['Stygian Onslaught', 'Not played yet', '⚔️'], self::endgameFields($sent[0])[0]);
    }

    /**
     * @return array<string, mixed> HoYoLAB body of an error answer (e.g. the game record is private)
     */
    private static function errorBody(): array
    {
        return ['retcode' => 10102, 'message' => 'Data is not public for the user', 'data' => null];
    }
}
