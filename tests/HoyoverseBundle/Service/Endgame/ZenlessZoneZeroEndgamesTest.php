<?php

namespace App\Tests\HoyoverseBundle\Service\Endgame;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveDeadlyAssaultDataException;
use App\HoyoverseBundle\Exception\RetrieveShiyuDefenseDataException;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\EndgamesReminderProfileHandler;
use App\HoyoverseBundle\Model\Endgame\DeadlyAssaultChallenge;
use App\HoyoverseBundle\Model\Endgame\ShiyuDefenseStage;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * ZenlessZoneZeroEndgamesTrait against HoYoLAB-shaped responses, denormalized by the application serializer.
 */
class ZenlessZoneZeroEndgamesTest extends KernelTestCase
{
    // Needed by CredentialNormalizer, built with the serializer; the test environment has no value for it
    private const string PASSWORDS_KEY_ENV = 'PASSWORDS_ENCRYPTION_KEY';

    /** @var array{server: mixed, env: mixed} */
    private array $previous;

    /** @var list<array{path: string, query: array<string, string>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->previous = ['server' => $_SERVER[self::PASSWORDS_KEY_ENV] ?? null, 'env' => $_ENV[self::PASSWORDS_KEY_ENV] ?? null];
        $_SERVER[self::PASSWORDS_KEY_ENV] ??= 'test-only-key';
        $_ENV[self::PASSWORDS_KEY_ENV] ??= 'test-only-key';
    }

    protected function tearDown(): void
    {
        // Shut the kernel down first, then give the environment back
        parent::tearDown();

        if ($this->previous['server'] === null) {
            unset($_SERVER[self::PASSWORDS_KEY_ENV]);
        } else {
            $_SERVER[self::PASSWORDS_KEY_ENV] = $this->previous['server'];
        }

        if ($this->previous['env'] === null) {
            unset($_ENV[self::PASSWORDS_KEY_ENV]);
        } else {
            $_ENV[self::PASSWORDS_KEY_ENV] = $this->previous['env'];
        }
    }

    private function createService(array $shiyuBody, array $assaultBody, ?LoggerInterface $logger = null): ZenlessZoneZeroService
    {
        self::bootKernel();

        $service = new ZenlessZoneZeroService($logger ?? new NullLogger());
        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setServerTimezoneResolver(new HoyoverseServerTimezoneResolver());
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url) use ($shiyuBody, $assaultBody): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->requests[] = ['path' => $path, 'query' => $query];

            $body = match (basename($path)) {
                'hadal_info_v2' => $shiyuBody,
                'hadal_mem_detail_v2' => $assaultBody,
                default => ['retcode' => -1, 'message' => 'unexpected endpoint'],
            };

            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        }));

        return $service;
    }

    private function createAccount(string $region = 'prod_gf_eu'): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())
            ->setGameId(ZenlessZoneZeroService::GAME_ID)
            ->setGameUid('1300000001')
            ->setRegion($region)
            ->setNickname('Proxy');

        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile($profile);
    }

    /**
     * @return array<string, GameEndgame>
     */
    private function endgamesByName(ZenlessZoneZeroService $service, string $region = 'prod_gf_eu'): array
    {
        $endgames = [];
        foreach ($service->getEndgamesData($this->createAccount($region)) as $endgame) {
            $endgames[$endgame->getName()] = $endgame;
        }

        return $endgames;
    }

    public function testContainerWiresTheTimezoneResolver(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(
            HoyoverseServerTimezoneResolver::class,
            static::getContainer()->get(ZenlessZoneZeroService::class)->getServerTimezoneResolver()
        );
    }

    public function testContainerWiresTheReminderWindow(): void
    {
        self::bootKernel();

        $this->assertSame(7, static::getContainer()->getParameter('hoyoverse.endgames_reminder.days_before_end'));
        $this->assertSame(7, static::getContainer()->get(EndgamesReminderProfileHandler::class)->getDaysBeforeEnd());
    }

    /**
     * Same call as ApiGameProfilesController::apiGameProfilesUpdateSettings().
     */
    public function testEndgameSettingsAreSavedOnlyForGamesWithEndgames(): void
    {
        self::bootKernel();
        $denormalizer = static::getContainer()->get('serializer');
        $payload = ['endgames_check' => true, 'endgames_full_clear' => true];

        $zenless = (new HoyoverseGameProfile())->setGameId(ZenlessZoneZeroService::GAME_ID);
        $denormalizer->denormalize($payload, HoyoverseGameProfile::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $zenless]);
        $this->assertTrue($zenless->isEndgamesCheck());
        $this->assertTrue($zenless->isEndgamesFullClear());

        // Genshin Impact does not implement HasEndgamesInterface (yet)
        $genshin = (new HoyoverseGameProfile())->setGameId(GenshinImpactService::GAME_ID);
        $denormalizer->denormalize($payload, HoyoverseGameProfile::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $genshin]);
        $this->assertFalse($genshin->isEndgamesCheck());
        $this->assertFalse($genshin->isEndgamesFullClear());
    }

    /**
     * Whole path of the scheduled task: HoYoLAB responses -> DTOs -> endgames -> notification.
     */
    public function testReminderHandlerWithHoyolabResponses(): void
    {
        $service = $this->createService(
            ZenlessEndgameFixture::shiyuDefense([[24999, 'A'], [25000, 'S'], [49870, 'S']]),
            ZenlessEndgameFixture::deadlyAssault([[19990, 2, 'Example Boss A'], [20000, 3, 'Example Boss B'], [52000, 3, 'Example Boss C']], []),
        );

        $sent = [];
        foreach ([false, true] as $fullClear) {
            $account = new HoyoverseAccount()->setUser(new User())->setCookie('ltoken_v2=test; ltuid_v2=123;');
            $profile = $this->createAccount()->getGameProfile()
                ->setAccount($account)
                ->setGameName('Zenless Zone Zero')
                ->setEndgamesCheck(true)
                ->setEndgamesFullClear($fullClear)
                ->setNotificationPlatforms(['discord']);

            $repo = $this->createStub(HoyoverseGameProfileRepository::class);
            $repo->method('find')->willReturn($profile);
            $locator = $this->createStub(ServiceCollectionInterface::class);
            $locator->method('has')->willReturn(true);
            $locator->method('get')->willReturn($service);
            $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
            $cookieUtils->method('parseCookie')->willReturn($this->createAccount()->getParsedCookie());
            $notificationService = $this->createStub(UnifiedNotificationService::class);
            $notificationService->method('sendToUser')->willReturnCallback(function (User $user, UniversalEmbed $embed) use (&$sent, $fullClear): void {
                $sent[] = [$fullClear, $embed];
            });

            $handler = new EndgamesReminderProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
            // Last day of Shiyu Defense, Deadly Assault ends in 5 days
            $handler->setClock(new MockClock('2026-03-19 15:00:00', 'Europe/Paris'));
            $handler(new EndgamesReminderProfileMessage(1));
        }

        // Both modes played: nothing to remind until the full-clear setting is on
        $this->assertCount(1, $sent);
        [$fullClear, $embed] = $sent[0];
        $this->assertTrue($fullClear);

        $fields = [];
        foreach ($embed->getFields() as $field) {
            $fields[$field->getName()] = $field->getValue();
        }
        $this->assertSame(
            "2/3 stages at S rank\nStage 1: 24,999/50,000 (A)\nEnds in 12 hr (2026-03-20 03:59 UTC+01:00)",
            $fields['Shiyu Defense']
        );
        $this->assertSame(
            "8/9 ★\nExample Boss A: 19,990/65,000 (2★)\nEnds in 5 days, 12 hr (2026-03-25 03:59 UTC+01:00)",
            $fields['Deadly Assault']
        );
        $this->assertArrayNotHasKey('Deadly Assault (Hard)', $fields);
    }

    public function testRequestsShiyuDefenseAndDeadlyAssaultDetailEndpoints(): void
    {
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), ZenlessEndgameFixture::deadlyAssault());

        $service->getShiyuDefense($this->createAccount());
        $service->getDeadlyAssault($this->createAccount());

        $this->assertCount(2, $this->requests);
        $this->assertSame('/event/game_record_zzz/api/zzz/hadal_info_v2', $this->requests[0]['path']);
        // Per-stage detail: no without_v2_detail parameter
        $this->assertSame(['role_id' => '1300000001', 'server' => 'prod_gf_eu', 'schedule_type' => '1'], $this->requests[0]['query']);
        $this->assertSame('/event/game_record_zzz/api/zzz/hadal_mem_detail_v2', $this->requests[1]['path']);
        $this->assertSame(['uid' => '1300000001', 'region' => 'prod_gf_eu', 'schedule_type' => '1'], $this->requests[1]['query']);
    }

    public function testDenormalizesShiyuDefense(): void
    {
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), ZenlessEndgameFixture::deadlyAssault());

        $data = $service->getShiyuDefense($this->createAccount());

        $this->assertSame('v2', $data->getVersion());
        $this->assertSame(61999, $data->getZoneId());
        $this->assertSame(ZenlessEndgameFixture::SHIYU_BEGIN_TIMESTAMP, (string) $data->getBeginTimestamp());
        $this->assertSame(ZenlessEndgameFixture::SHIYU_END_TIMESTAMP, (string) $data->getEndTimestamp());
        $this->assertSame('2026-03-06 04:00:00', $data->getBeginTime()?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-20 03:59:59', $data->getEndTime()?->format('Y-m-d H:i:s'));
        $this->assertTrue($data->getPassFifthFloor());
        $this->assertSame(31250 + 42010 + 27480, $data->getScore());
        $this->assertSame(150000, $data->getMaxScore());
        $this->assertSame('S', $data->getRating());

        $stages = $data->getStages();
        $this->assertCount(3, $stages);
        $this->assertContainsOnlyInstancesOf(ShiyuDefenseStage::class, $stages);
        $this->assertSame([61999051, 61999052, 61999053], array_map(static fn (ShiyuDefenseStage $s) => $s->getLayerId(), $stages));
        $this->assertSame([31250, 42010, 27480], array_map(static fn (ShiyuDefenseStage $s) => $s->getScore(), $stages));
        $this->assertSame(['S', 'S', 'S'], array_map(static fn (ShiyuDefenseStage $s) => $s->getRating(), $stages));
        $this->assertSame(50000, $stages[0]->getMaxScore());
        $this->assertTrue($data->isPlayed());
        $this->assertTrue($data->isFullyCleared());
    }

    public function testDenormalizesDeadlyAssault(): void
    {
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), ZenlessEndgameFixture::deadlyAssault());

        $data = $service->getDeadlyAssault($this->createAccount());

        $this->assertTrue($data->getHasData());
        $this->assertSame(699901, $data->getZoneId());
        $this->assertSame('2026-03-13 04:00:00', $data->getStartTime()?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-25 03:59:59', $data->getEndTime()?->format('Y-m-d H:i:s'));
        $this->assertSame(25120 + 31870 + 44300, $data->getTotalScore());
        $this->assertSame(9, $data->getTotalStar());
        $this->assertSame(195000, $data->getTotalMaxScore());
        $this->assertSame(65000, $data->getRoomMaxScore());
        $this->assertTrue($data->getHasHard());

        $challenges = $data->getChallenges();
        $this->assertCount(3, $challenges);
        $this->assertContainsOnlyInstancesOf(DeadlyAssaultChallenge::class, $challenges);
        $this->assertSame(['Example Boss A', 'Example Boss B', 'Example Boss C'], array_map(static fn (DeadlyAssaultChallenge $c) => $c->getBossName(), $challenges));
        $this->assertSame([25120, 31870, 44300], array_map(static fn (DeadlyAssaultChallenge $c) => $c->getScore(), $challenges));
        $this->assertSame([3, 3, 3], array_map(static fn (DeadlyAssaultChallenge $c) => $c->getStar(), $challenges));
        $this->assertCount(1, $data->getHardChallenges());
        $this->assertSame('Example Hard Boss', $data->getHardChallenges()[0]->getBossName());
        $this->assertSame(30410, $data->getHardChallenges()[0]->getScore());
        $this->assertTrue($data->isFullyCleared());
        $this->assertTrue($data->isHardFullyCleared());
    }

    public function testEndgamesOfAPlayerAtTheMaximum(): void
    {
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), ZenlessEndgameFixture::deadlyAssault());

        $endgames = $this->endgamesByName($service);

        $this->assertSame(['Shiyu Defense', 'Deadly Assault', 'Deadly Assault (Hard)'], array_keys($endgames));
        foreach ($endgames as $endgame) {
            $this->assertTrue($endgame->isUnlocked());
            $this->assertTrue($endgame->isPlayed());
            $this->assertTrue($endgame->isMaxed());
            $this->assertFalse($endgame->needsReminder(true));
        }

        // Shiyu Defense: from the epoch, shown in the server offset of the profile
        $shiyuEnd = $endgames['Shiyu Defense']->getEndTime();
        $this->assertSame((int) ZenlessEndgameFixture::SHIYU_END_TIMESTAMP, $shiyuEnd->getTimestamp());
        $this->assertSame('2026-03-20 03:59:59 +01:00', $shiyuEnd->format('Y-m-d H:i:s P'));
        $this->assertSame('3/3 stages at S rank', $endgames['Shiyu Defense']->getFormattedProgress());

        // Deadly Assault: server wall-clock read in the server offset (EU = UTC+01:00)
        $assaultEnd = $endgames['Deadly Assault']->getEndTime();
        $this->assertSame('2026-03-25T02:59:59+00:00', $assaultEnd->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertEquals($assaultEnd, $endgames['Deadly Assault (Hard)']->getEndTime());
        $this->assertSame('9/9 ★', $endgames['Deadly Assault']->getFormattedProgress());
        $this->assertSame('3/3 ★', $endgames['Deadly Assault (Hard)']->getFormattedProgress());
    }

    public function testEndgamesBelowTheMaximumListEveryStageAndBoss(): void
    {
        $service = $this->createService(
            ZenlessEndgameFixture::shiyuDefense([[24999, 'A'], [25000, 'S'], [49870, 'S']]),
            ZenlessEndgameFixture::deadlyAssault([[19990, 2, 'Example Boss A'], [20000, 3, 'Example Boss B'], [52000, 3, 'Example Boss C']], []),
        );

        $endgames = $this->endgamesByName($service);

        $shiyu = $endgames['Shiyu Defense'];
        $this->assertTrue($shiyu->isPlayed());
        $this->assertFalse($shiyu->isMaxed());
        $this->assertSame('2/3 stages at S rank', $shiyu->getFormattedProgress());
        $this->assertSame(
            ['Stage 1: 24,999/50,000 (A)', 'Stage 2: 25,000/50,000 (S)', 'Stage 3: 49,870/50,000 (S)'],
            array_map(static fn ($stage) => $stage->getFormattedOutput(), $shiyu->getStages())
        );
        $this->assertSame([false, true, true], array_map(static fn ($stage) => $stage->isMaxed(), $shiyu->getStages()));

        $assault = $endgames['Deadly Assault'];
        $this->assertTrue($assault->isPlayed());
        $this->assertSame('8/9 ★', $assault->getFormattedProgress());
        $this->assertSame('Example Boss A: 19,990/65,000 (2★)', $assault->getStages()[0]->getFormattedOutput());
        $this->assertFalse($assault->getStages()[0]->isMaxed());
        $this->assertTrue($assault->getStages()[1]->isMaxed());

        // Hard mode stays locked below nine stars
        $this->assertFalse($endgames['Deadly Assault (Hard)']->isUnlocked());
        $this->assertFalse($endgames['Deadly Assault (Hard)']->needsReminder(true));
    }

    public function testHardModeUnlockedButNotPlayed(): void
    {
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), ZenlessEndgameFixture::deadlyAssault(hard: []));

        $hard = $this->endgamesByName($service)['Deadly Assault (Hard)'];

        $this->assertTrue($hard->isUnlocked());
        $this->assertFalse($hard->isPlayed());
        $this->assertTrue($hard->needsReminder(false));
    }

    public function testSummaryOnlyShiyuDefenseCountsAsPlayedButNotMaxed(): void
    {
        $body = ZenlessEndgameFixture::shiyuDefense();
        // Shape of a response requested with without_v2_detail=true
        $body['data']['hadal_info_v2']['fitfh_layer_detail'] = null;
        $body['data']['hadal_info_v2']['fourth_layer_detail'] = null;
        $service = $this->createService($body, ZenlessEndgameFixture::deadlyAssault());

        $shiyu = $this->endgamesByName($service)['Shiyu Defense'];

        $this->assertTrue($shiyu->isPlayed());
        $this->assertFalse($shiyu->isMaxed());
        $this->assertSame([], $shiyu->getStages());
    }

    /**
     * Never seen for real: assumed shapes of a period the player has not played.
     */
    public function testUnplayedPeriodsAreNotPlayed(): void
    {
        $shiyu = ZenlessEndgameFixture::shiyuDefense([]);
        $shiyu['data']['hadal_info_v2']['brief']['score'] = 0;
        $shiyu['data']['hadal_info_v2']['brief']['rating'] = '';
        $shiyu['data']['hadal_info_v2']['fitfh_layer_detail'] = null;
        $shiyu['data']['hadal_info_v2']['fourth_layer_detail'] = null;

        $assault = ZenlessEndgameFixture::deadlyAssault([], []);
        $assault['data']['has_data'] = false;
        $assault['data']['has_hard'] = false;

        $endgames = $this->endgamesByName($this->createService($shiyu, $assault));

        $this->assertFalse($endgames['Shiyu Defense']->isPlayed());
        $this->assertTrue($endgames['Shiyu Defense']->needsReminder(false));
        $this->assertNotNull($endgames['Shiyu Defense']->getEndTime());
        $this->assertFalse($endgames['Deadly Assault']->isPlayed());
        $this->assertSame('0/9 ★', $endgames['Deadly Assault']->getFormattedProgress());
        $this->assertNotNull($endgames['Deadly Assault']->getEndTime());
        $this->assertFalse($endgames['Deadly Assault (Hard)']->isUnlocked());
    }

    /**
     * @return iterable<string, array{0: array, 1: array}>
     */
    public static function missingDataProvider(): iterable
    {
        yield 'no data at all' => [['retcode' => 0, 'message' => 'OK', 'data' => null], ['retcode' => 0, 'message' => 'OK', 'data' => null]];
        yield 'empty data' => [['retcode' => 0, 'message' => 'OK', 'data' => []], ['retcode' => 0, 'message' => 'OK', 'data' => []]];
        yield 'v2 without hadal_info_v2, lists null' => [
            ['retcode' => 0, 'message' => 'OK', 'data' => ['hadal_ver' => 'v2', 'nick_name' => 'Proxy']],
            ['retcode' => 0, 'message' => 'OK', 'data' => ['has_data' => false, 'list' => null, 'hard_list' => null, 'start_time' => null, 'end_time' => null]],
        ];
        yield 'hadal_info_v2 null, brief missing' => [
            ['retcode' => 0, 'message' => 'OK', 'data' => ['hadal_ver' => 'v2', 'hadal_info_v2' => null]],
            ['retcode' => 0, 'message' => 'OK', 'data' => ['has_data' => true, 'list' => [], 'total_score' => 0]],
        ];
        yield 'brief null, detail without list' => [
            ['retcode' => 0, 'message' => 'OK', 'data' => ['hadal_ver' => 'v2', 'hadal_info_v2' => ['zone_id' => 61999, 'brief' => null, 'fitfh_layer_detail' => [], 'end_time' => '']]],
            ['retcode' => 0, 'message' => 'OK', 'data' => ['list' => [['score' => 0, 'star' => 0, 'boss' => []]]]],
        ];
    }

    #[DataProvider('missingDataProvider')]
    public function testMissingDataIsNotPlayedAndHasNoEnd(array $shiyuBody, array $assaultBody): void
    {
        $endgames = $this->endgamesByName($this->createService($shiyuBody, $assaultBody));

        $this->assertSame(['Shiyu Defense', 'Deadly Assault', 'Deadly Assault (Hard)'], array_keys($endgames));
        foreach ($endgames as $name => $endgame) {
            $this->assertFalse($endgame->isPlayed(), $name);
            $this->assertNull($endgame->getEndTime(), $name);
        }
        $this->assertFalse($endgames['Deadly Assault (Hard)']->isUnlocked());
    }

    public function testUnknownShiyuDefenseVersionIsSkippedAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Unknown Shiyu Defense response version "v3"'));

        $shiyu = ['retcode' => 0, 'message' => 'OK', 'data' => ['hadal_ver' => 'v3', 'hadal_info_v3' => ['score' => 1]]];
        $service = $this->createService($shiyu, ZenlessEndgameFixture::deadlyAssault(), $logger);

        $this->assertSame(['Deadly Assault', 'Deadly Assault (Hard)'], array_keys($this->endgamesByName($service)));
    }

    /**
     * @return array<string, mixed> HoYoLAB body of an error answer (e.g. the game record is private)
     */
    private static function errorBody(): array
    {
        return ['retcode' => 10102, 'message' => 'Data is not public for the user', 'data' => null];
    }

    public function testUnreadableShiyuDefenseDoesNotHideDeadlyAssault(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('Shiyu Defense'),
                    $this->stringContains(RetrieveShiyuDefenseDataException::class),
                    $this->stringContains('10102'),
                    $this->logicalNot($this->stringContains('Data is not public'))
                )
            );
        $service = $this->createService(self::errorBody(), ZenlessEndgameFixture::deadlyAssault(), $logger);

        $this->assertSame(['Deadly Assault', 'Deadly Assault (Hard)'], array_keys($this->endgamesByName($service)));
    }

    public function testUnreadableDeadlyAssaultDoesNotHideShiyuDefense(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('Deadly Assault'),
                $this->stringContains(RetrieveDeadlyAssaultDataException::class)
            ));
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), self::errorBody(), $logger);

        $this->assertSame(['Shiyu Defense'], array_keys($this->endgamesByName($service)));
    }

    public function testUnreadableDeadlyAssaultShapeDoesNotHideShiyuDefense(): void
    {
        $assault = ZenlessEndgameFixture::deadlyAssault();
        // Not a date object nor null: the denormalization throws
        $assault['data']['end_time'] = 'not a date';
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), $assault);

        $this->assertSame(['Shiyu Defense'], array_keys($this->endgamesByName($service)));
    }

    public function testWhenEveryModeFailsTheFirstErrorIsThrown(): void
    {
        $service = $this->createService(self::errorBody(), self::errorBody());

        $this->expectException(RetrieveShiyuDefenseDataException::class);
        $service->getEndgamesData($this->createAccount());
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function emptyDateProvider(): iterable
    {
        yield 'all null' => [['year' => null, 'month' => null, 'day' => null, 'hour' => null, 'minute' => null, 'second' => null]];
        yield 'all zero' => [['year' => 0, 'month' => 0, 'day' => 0, 'hour' => 0, 'minute' => 0, 'second' => 0]];
        yield 'missing parts' => [['year' => 2026]];
        yield 'empty array' => [[]];
    }

    #[DataProvider('emptyDateProvider')]
    public function testEmptyDateObjectsAreAnAbsentEnd(array $emptyDate): void
    {
        $shiyu = ZenlessEndgameFixture::shiyuDefense();
        unset($shiyu['data']['hadal_info_v2']['end_time']);
        $shiyu['data']['hadal_info_v2']['hadal_begin_time'] = $emptyDate;
        $shiyu['data']['hadal_info_v2']['hadal_end_time'] = $emptyDate;
        $assault = ZenlessEndgameFixture::deadlyAssault();
        $assault['data']['start_time'] = $emptyDate;
        $assault['data']['end_time'] = $emptyDate;

        $endgames = $this->endgamesByName($this->createService($shiyu, $assault));

        $this->assertSame(['Shiyu Defense', 'Deadly Assault', 'Deadly Assault (Hard)'], array_keys($endgames));
        foreach ($endgames as $name => $endgame) {
            $this->assertNull($endgame->getEndTime(), $name);
        }
    }

    public function testShiyuDefenseEndFallsBackToTheWallClockWithoutTimestamp(): void
    {
        $body = ZenlessEndgameFixture::shiyuDefense();
        unset($body['data']['hadal_info_v2']['end_time']);
        $service = $this->createService($body, ZenlessEndgameFixture::deadlyAssault());

        $end = $this->endgamesByName($service, 'prod_gf_us')['Shiyu Defense']->getEndTime();

        // 03:59:59 server time of an America profile (UTC-05:00)
        $this->assertSame('2026-03-20T08:59:59+00:00', $end->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function regionProvider(): iterable
    {
        yield 'Europe' => ['prod_gf_eu', '2026-03-25T02:59:59+00:00'];
        yield 'America' => ['prod_gf_us', '2026-03-25T08:59:59+00:00'];
        yield 'Asia' => ['prod_gf_jp', '2026-03-24T19:59:59+00:00'];
    }

    #[DataProvider('regionProvider')]
    public function testDeadlyAssaultEndIsReadInTheServerTimeOfTheProfile(string $region, string $expectedUtc): void
    {
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), ZenlessEndgameFixture::deadlyAssault());

        // The wall-clock objects are parsed in the PHP default timezone: it must not matter
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $end = $this->endgamesByName($service, $region)['Deadly Assault']->getEndTime();
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $this->assertSame($expectedUtc, $end->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }
}
