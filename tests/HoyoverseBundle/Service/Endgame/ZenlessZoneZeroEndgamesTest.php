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
use App\HoyoverseBundle\Service\HoyolabUtilsService;
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
    /** @var list<array{path: string, query: array<string, string>}> */
    private array $requests = [];

    private function createService(array $shiyuBody, array $assaultBody, ?LoggerInterface $logger = null): ZenlessZoneZeroService
    {
        self::bootKernel();

        $service = new ZenlessZoneZeroService($logger ?? new NullLogger());
        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setServerTimezoneResolver(new HoyoverseServerTimezoneResolver());
        $service->setHoyolabUtils(new HoyolabUtilsService());
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
        foreach (ZenlessZoneZeroService::getEndgameDefinitions() as $definition) {
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
        ZenlessZoneZeroService $service,
        ?array $settings,
        array &$sent,
        string $now = '2026-03-19 15:00:00',
        ?LoggerInterface $logger = null,
    ): EndgamesReminderProfileHandler {
        $account = new HoyoverseAccount()->setUser(new User())->setCookie('ltoken_v2=test; ltuid_v2=123;');
        $profile = $this->createAccount()->getGameProfile()
            ->setAccount($account)
            ->setGameName('Zenless Zone Zero')
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

    public function testContainerWiresTheTimezoneResolver(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(
            HoyoverseServerTimezoneResolver::class,
            static::getContainer()->get(ZenlessZoneZeroService::class)->getServerTimezoneResolver()
        );
    }

    public function testContainerWiresTheReminderWindows(): void
    {
        self::bootKernel();

        // Same keys and types, whatever the order of the keys
        $expected = [
            'hsr' => [
                'memory_of_chaos' => ['days_before_end' => 7],
                'pure_fiction' => ['days_before_end' => 7],
                'apocalyptic_shadow' => ['days_before_end' => 7],
                'anomaly_arbitration' => ['days_before_end' => 7],
            ],
            'zzz' => ['shiyu_defense' => ['days_before_end' => 7], 'deadly_assault' => ['days_before_end' => 7]],
        ];
        $actual = static::getContainer()->getParameter('hoyoverse.endgames_reminder');
        $sort = static function (array &$tree) use (&$sort): void {
            ksort($tree);
            foreach ($tree as &$branch) {
                if (is_array($branch)) {
                    $sort($branch);
                }
            }
        };
        $sort($expected);
        $sort($actual);
        $this->assertSame($expected, $actual);
        $handler = static::getContainer()->get(EndgamesReminderProfileHandler::class);
        foreach (ZenlessZoneZeroService::getEndgameDefinitions() as $definition) {
            $this->assertSame(7, $handler->getDaysBeforeEnd('zzz', $definition));
        }
    }

    public function testDeclaresShiyuDefenseAndDeadlyAssaultWithItsHardMode(): void
    {
        $this->assertSame('zzz', ZenlessZoneZeroService::getEndgamesConfigKey());

        $declared = [];
        foreach (ZenlessZoneZeroService::getEndgameDefinitions() as $definition) {
            foreach ($definition->variants as $variant) {
                $declared[$definition->key][$variant->key] = [$variant->name, $variant->defaultReminder];
            }
        }

        $this->assertSame([
            'shiyu_defense' => ['reminder' => ['Shiyu Defense', EndgameReminder::NotPlayed]],
            'deadly_assault' => [
                'reminder' => ['Deadly Assault', EndgameReminder::NotPlayed],
                // Optional content: reminded only if the player asks
                'hard_reminder' => ['Deadly Assault (Hard)', EndgameReminder::Off],
            ],
        ], $declared);
    }

    /**
     * Same calls as ApiGameProfilesController::apiGameProfilesUpdateSettings() and apiGameProfilesIdGet().
     */
    public function testEndgameSettingsAreSavedOnlyForGamesWithEndgames(): void
    {
        self::bootKernel();
        $serializer = static::getContainer()->get('serializer');
        $payload = [
            'endgames_check' => true,
            'endgames_settings' => [
                'shiyu_defense' => ['reminder' => 'full_clear'],
                // Invalid value, unknown variant and unknown mode: not stored
                'deadly_assault' => ['reminder' => 'always', 'extra' => 'full_clear'],
                'spiral_abyss' => ['reminder' => 'full_clear'],
            ],
        ];

        $zenless = (new HoyoverseGameProfile())->setGameId(ZenlessZoneZeroService::GAME_ID);
        $serializer->denormalize($payload, HoyoverseGameProfile::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $zenless]);
        $this->assertTrue($zenless->isEndgamesCheck());
        $this->assertSame(
            ['shiyu_defense' => ['reminder' => 'full_clear'], 'deadly_assault' => ['reminder' => 'not_played', 'hard_reminder' => 'off']],
            $zenless->getEndgamesSettings()
        );

        // A partial update keeps what it does not send
        $serializer->denormalize(['endgames_settings' => ['deadly_assault' => ['hard_reminder' => 'full_clear']]], HoyoverseGameProfile::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $zenless]);
        $this->assertSame(
            ['shiyu_defense' => ['reminder' => 'full_clear'], 'deadly_assault' => ['reminder' => 'not_played', 'hard_reminder' => 'full_clear']],
            $zenless->getEndgamesSettings()
        );

        // Read back as the settings drawer gets it: effective values and the modes to build the form from
        $json = json_decode($serializer->serialize($zenless->setEndgamesSettings(null), 'json'), true);
        $this->assertSame(
            ['shiyu_defense' => ['reminder' => 'not_played'], 'deadly_assault' => ['reminder' => 'not_played', 'hard_reminder' => 'off']],
            $json['endgames_settings']
        );
        $this->assertSame(['shiyu_defense', 'deadly_assault'], array_column($json['endgames'], 'key'));
        $this->assertSame(
            [['key' => 'reminder', 'name' => 'Deadly Assault', 'default' => 'not_played'], ['key' => 'hard_reminder', 'name' => 'Deadly Assault (Hard)', 'default' => 'off']],
            $json['endgames'][1]['variants']
        );

        // Genshin Impact does not implement HasEndgamesInterface (yet)
        $genshin = (new HoyoverseGameProfile())->setGameId(GenshinImpactService::GAME_ID);
        $serializer->denormalize($payload, HoyoverseGameProfile::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $genshin]);
        $this->assertFalse($genshin->isEndgamesCheck());
        $this->assertNull($genshin->getEndgamesSettings());
        $json = json_decode($serializer->serialize($genshin, 'json'), true);
        $this->assertArrayNotHasKey('endgames_settings', $json);
        $this->assertArrayNotHasKey('endgames', $json);
    }

    /**
     * Whole path of the scheduled task: HoYoLAB responses -> DTOs -> endgames -> notification.
     */
    public function testReminderHandlerWithHoyolabResponses(): void
    {
        $service = $this->createService(
            ZenlessEndgameFixture::shiyuDefense([[24999, 'A'], [25000, 'S'], [49870, 'S']], 'A'),
            ZenlessEndgameFixture::deadlyAssault([[19990, 2, 'Example Boss A'], [20000, 3, 'Example Boss B'], [52000, 3, 'Example Boss C']], [], hasHard: false),
        );

        // Default settings: both modes played, nothing to remind
        $sent = [];
        $this->createHandler($service, null, $sent)(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $sent);

        // Full clear: last day of Shiyu Defense, Deadly Assault ends in 5 days; Hard mode locked
        $this->createHandler($service, ['shiyu_defense' => ['reminder' => 'full_clear'], 'deadly_assault' => ['reminder' => 'full_clear', 'hard_reminder' => 'full_clear']], $sent)(new EndgamesReminderProfileMessage(1));
        $this->assertCount(1, $sent);
        $this->assertSame('Zenless Zone Zero - Endgames Reminder', $sent[0]->getTitle());
        $this->assertSame([
            // One line per floor with its own icon
            ['Shiyu Defense', "⚔️\u{00A0}\u{00A0}Floor 4: A\n⚔️\u{00A0}\u{00A0}Floor 5: 2/3 S\n↳ Stage 1: A (24,999)", null],
            [EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, null],
            ['Ends in', '12 hr, 59 min', '🕒'],
            ['Deadly Assault', '8/9 ★', '⚔️'],
            [EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, null],
            ['Ends in', '5 days, 12 hr', '🕒'],
        ], self::endgameFields($sent[0]));
        foreach (array_slice($sent[0]->getFields(), 3) as $field) {
            $this->assertTrue($field->isInline());
        }
    }

    public function testUnreadableModeDoesNotHideTheOther(): void
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
        $service = $this->createService(self::errorBody(), ZenlessEndgameFixture::deadlyAssault([], [], hasHard: false));

        $sent = [];
        $this->createHandler($service, null, $sent, logger: $logger)(new EndgamesReminderProfileMessage(1));

        $this->assertCount(1, $sent);
        $this->assertSame(['Deadly Assault', 'Not played yet', '⚔️'], self::endgameFields($sent[0])[0]);
    }

    public function testUnreadableDeadlyAssaultShapeDoesNotHideShiyuDefense(): void
    {
        $assault = ZenlessEndgameFixture::deadlyAssault();
        // Not a date object nor null: the denormalization throws
        $assault['data']['end_time'] = 'not a date';
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense([], null), $assault);

        $sent = [];
        $this->createHandler($service, null, $sent)(new EndgamesReminderProfileMessage(1));

        $this->assertCount(1, $sent);
        $this->assertSame(['Shiyu Defense', 'Not played yet', '⚔️'], self::endgameFields($sent[0])[0]);
    }

    public function testWhenEveryModeFailsTheFirstErrorIsThrown(): void
    {
        $service = $this->createService(self::errorBody(), self::errorBody());

        $sent = [];
        $this->expectException(RetrieveShiyuDefenseDataException::class);
        $this->createHandler($service, null, $sent)(new EndgamesReminderProfileMessage(1));
    }

    public function testFetchEndgameThrowsWhenTheRequestFails(): void
    {
        $service = $this->createService(self::errorBody(), self::errorBody());

        $this->expectException(RetrieveDeadlyAssaultDataException::class);
        $service->fetchEndgame(ZenlessZoneZeroService::getEndgameDefinitions()[1], $this->createAccount());
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
        $this->assertSame('S', $data->getFourthFloorRating());
        $this->assertCount(1, $data->getFourthFloorChallenges());
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
            $this->assertFalse($endgame->needsReminder(EndgameReminder::FullClear));
        }
        $this->assertSame('hard_reminder', $endgames['Deadly Assault (Hard)']->getVariant());

        // Shiyu Defense: from the epoch, shown in the server offset of the profile
        $shiyuEnd = $endgames['Shiyu Defense']->getEndTime();
        $this->assertSame((int) ZenlessEndgameFixture::SHIYU_END_TIMESTAMP, $shiyuEnd->getTimestamp());
        $this->assertSame('2026-03-20 03:59:59 +01:00', $shiyuEnd->format('Y-m-d H:i:s P'));
        $this->assertSame(["⚔️\u{00A0}\u{00A0}Floor 4: S", "⚔️\u{00A0}\u{00A0}Floor 5: 3/3 S"], $endgames['Shiyu Defense']->getProgressLines());

        // Deadly Assault: server wall-clock read in the server offset (EU = UTC+01:00)
        $assaultEnd = $endgames['Deadly Assault']->getEndTime();
        $this->assertSame('2026-03-25T02:59:59+00:00', $assaultEnd->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertEquals($assaultEnd, $endgames['Deadly Assault (Hard)']->getEndTime());
        $this->assertSame(['9/9 ★'], $endgames['Deadly Assault']->getProgressLines());
        $this->assertSame(['3/3 ★'], $endgames['Deadly Assault (Hard)']->getProgressLines());
    }

    public function testEndgamesBelowTheMaximumListWhatIsLeft(): void
    {
        $service = $this->createService(
            ZenlessEndgameFixture::shiyuDefense([[24999, 'A'], [45000, 'B'], [49870, 'S']], 'S'),
            ZenlessEndgameFixture::deadlyAssault([[19990, 2, 'Example Boss A'], [20000, 3, 'Example Boss B'], [52000, 3, 'Example Boss C']], [], hasHard: false),
        );

        $endgames = $this->endgamesByName($service);

        $shiyu = $endgames['Shiyu Defense'];
        $this->assertTrue($shiyu->isPlayed());
        $this->assertFalse($shiyu->isMaxed());
        // Only the stages below S rank, whatever their score
        $this->assertSame(["⚔️\u{00A0}\u{00A0}Floor 4: S", "⚔️\u{00A0}\u{00A0}Floor 5: 1/3 S", '↳ Stage 1: A (24,999)', '↳ Stage 2: B (45,000)'], $shiyu->getProgressLines());

        $assault = $endgames['Deadly Assault'];
        $this->assertTrue($assault->isPlayed());
        $this->assertFalse($assault->isMaxed());
        $this->assertSame(['8/9 ★'], $assault->getProgressLines());

        // Hard mode stays locked below 9★ without Hard mode data
        $this->assertFalse($endgames['Deadly Assault (Hard)']->isUnlocked());
        $this->assertFalse($endgames['Deadly Assault (Hard)']->needsReminder(EndgameReminder::FullClear));
    }

    public function testFourthFloorOnlyIsPlayed(): void
    {
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense([], 'A'), ZenlessEndgameFixture::deadlyAssault());

        $shiyu = $this->endgamesByName($service)['Shiyu Defense'];

        $this->assertTrue($shiyu->isPlayed());
        $this->assertFalse($shiyu->isMaxed());
        $this->assertSame(["⚔️\u{00A0}\u{00A0}Floor 4: A", "⚔️\u{00A0}\u{00A0}Floor 5: not played"], $shiyu->getProgressLines());
    }

    /**
     * Shape seen live on 2026-11-22 right after a 9★ run: the totals are there, the per-boss lists come later.
     */
    public function testDeadlyAssaultTotalsBeforeTheBossList(): void
    {
        $assault = ZenlessEndgameFixture::deadlyAssault([], [], hasHard: false);
        $assault['data']['total_score'] = 94473;
        $assault['data']['total_star'] = 9;
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), $assault);

        $endgames = $this->endgamesByName($service);

        $this->assertTrue($endgames['Deadly Assault']->isPlayed());
        $this->assertTrue($endgames['Deadly Assault']->isMaxed());
        $this->assertSame(['9/9 ★'], $endgames['Deadly Assault']->getProgressLines());
        // 9★: Hard mode open even before has_hard says so
        $this->assertTrue($endgames['Deadly Assault (Hard)']->isUnlocked());
        $this->assertFalse($endgames['Deadly Assault (Hard)']->isPlayed());
    }

    /**
     * Shape seen live on 2026-11-22 once the boss list arrived: 9★, Hard mode not tried yet, has_hard still false.
     */
    public function testNineStarsUnlockHardModeWithoutHardData(): void
    {
        $service = $this->createService(
            ZenlessEndgameFixture::shiyuDefense(),
            ZenlessEndgameFixture::deadlyAssault([[22349, 3, 'Example Boss A'], [21258, 3, 'Example Boss B'], [50866, 3, 'Example Boss C']], [], hasHard: false),
        );

        $hard = $this->endgamesByName($service)['Deadly Assault (Hard)'];

        $this->assertTrue($hard->isUnlocked());
        $this->assertFalse($hard->isPlayed());
        $this->assertTrue($hard->needsReminder(EndgameReminder::NotPlayed));
    }

    public function testHardModeUnlockedButNotPlayed(): void
    {
        $service = $this->createService(ZenlessEndgameFixture::shiyuDefense(), ZenlessEndgameFixture::deadlyAssault(hard: []));

        $hard = $this->endgamesByName($service)['Deadly Assault (Hard)'];

        $this->assertTrue($hard->isUnlocked());
        $this->assertFalse($hard->isPlayed());
        $this->assertSame(['0/3 ★'], $hard->getProgressLines());
        $this->assertTrue($hard->needsReminder(EndgameReminder::NotPlayed));
        $this->assertFalse($hard->needsReminder(EndgameReminder::Off));
    }

    /**
     * The totals can come before the floor detail (seen for Deadly Assault right after a run): played, but the
     * distance from the maximum is unknown, so Shiyu Defense is left out of this run instead of a wrong reminder.
     */
    public function testShiyuDefenseScoreWithoutFloorDetailIsSkipped(): void
    {
        $body = ZenlessEndgameFixture::shiyuDefense();
        $body['data']['hadal_info_v2']['fitfh_layer_detail'] = null;
        $body['data']['hadal_info_v2']['fourth_layer_detail'] = null;
        $service = $this->createService($body, ZenlessEndgameFixture::deadlyAssault());

        $this->assertSame([], $service->fetchEndgame(ZenlessZoneZeroService::getEndgameDefinitions()[0], $this->createAccount()));

        $sent = [];
        $this->createHandler($this->createService($body, ZenlessEndgameFixture::deadlyAssault()), ['shiyu_defense' => ['reminder' => 'full_clear']], $sent)(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $sent);
    }

    /**
     * Deadly Assault: shape seen live on 2026-11-22 for a period not played yet (dates present, everything else empty).
     * Shiyu Defense: not seen yet, assumed.
     */
    public function testUnplayedPeriodsAreNotPlayed(): void
    {
        $shiyu = ZenlessEndgameFixture::shiyuDefense([], null);
        $shiyu['data']['hadal_info_v2']['brief']['score'] = 0;
        $shiyu['data']['hadal_info_v2']['brief']['rating'] = '';
        $shiyu['data']['hadal_info_v2']['fitfh_layer_detail'] = null;

        $assault = ZenlessEndgameFixture::deadlyAssault([], [], hasHard: false);
        $assault['data']['has_data'] = false;
        $assault['data']['total_max_score'] = 0;
        $assault['data']['room_max_score'] = 0;

        $endgames = $this->endgamesByName($this->createService($shiyu, $assault));

        $this->assertFalse($endgames['Shiyu Defense']->isPlayed());
        $this->assertTrue($endgames['Shiyu Defense']->needsReminder(EndgameReminder::NotPlayed));
        $this->assertNotNull($endgames['Shiyu Defense']->getEndTime());
        $this->assertFalse($endgames['Deadly Assault']->isPlayed());
        $this->assertSame(['0/9 ★'], $endgames['Deadly Assault']->getProgressLines());
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
