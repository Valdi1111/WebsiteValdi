<?php

namespace App\Tests\HoyoverseBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\DeclarePityRequest;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\HoyoverseBundle\Controller\ApiGameProfilesController;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Message\Profile\ImportPullsBackupMessage;
use App\HoyoverseBundle\Model\Pull\PullBackupImporterInterface;
use App\GamesBundle\Model\Pull\PullBackupValidationResult;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\HoyoversePullStatsCalculator;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\ManualPullSuggestionProvider;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\Pull\PullBackupImporterManager;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

class ApiGameProfilesPullsImportControllerTest extends TestCase
{
    private string $tempProjectDir;

    protected function setUp(): void
    {
        $this->tempProjectDir = sys_get_temp_dir() . '/valdi_ctrl_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempProjectDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempProjectDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function createUser(int $id = 1): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function createAccountAndProfile(User $user, int $profileId = 10, int $gameId = GenshinImpactService::GAME_ID): array
    {
        $account = (new HoyoverseAccount())->setUser($user);
        $profile = (new HoyoverseGameProfile())
            ->setAccount($account)
            ->setGameId($gameId)
            ->setGameBiz(GenshinImpactService::GAME_BIZ)
            ->setNickname('Traveler');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, $profileId);

        return [$account, $profile];
    }

    private function createController(): ApiGameProfilesController
    {
        $giService = new GenshinImpactService(new NullLogger());
        $hsrService = new HonkaiStarRailService(new NullLogger());
        $locator = new ServiceLocator([
            GenshinImpactService::GAME_ID => static fn () => $giService,
            HonkaiStarRailService::GAME_ID => static fn () => $hsrService,
        ]);

        $controller = new ApiGameProfilesController(
            $this->createStub(EntityManagerInterface::class),
            $locator,
            $this->tempProjectDir,
        );

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR)
        );

        $container = new Container();
        $container->set('serializer', $serializer);
        $controller->setContainer($container);

        return $controller;
    }

    private function createFakeImporter(
        string $key,
        string $supportedClass,
        array $extensions,
        PullBackupValidationResult $validationResult,
    ): PullBackupImporterInterface {
        return new class($key, $supportedClass, $extensions, $validationResult) implements PullBackupImporterInterface {
            private static string $staticKey = '';
            /** @var list<class-string> */
            private static array $staticGames = [];

            /**
             * @param list<string> $extensions
             */
            public function __construct(
                private readonly string $key,
                string $supportedClass,
                private readonly array $extensions,
                private readonly PullBackupValidationResult $validationResult,
            ) {
                self::$staticKey = $key;
                self::$staticGames = [$supportedClass];
            }

            public static function getKey(): string { return self::$staticKey; }
            public function getLabel(): string { return 'Label ' . $this->key; }
            public function getDescription(): string { return 'Desc'; }
            public function getAcceptedExtensions(): array { return $this->extensions; }
            public static function getSupportedGameServices(): array { return self::$staticGames; }
            public function validate(string $filePath): PullBackupValidationResult { return $this->validationResult; }
            public function parse(HoyoverseGameProfile $profile, string $filePath): array { return []; }
        };
    }

    private function createUploadedFile(string $content, string $originalName, string $mimeType = 'application/json'): UploadedFile
    {
        $source = $this->tempProjectDir . '/upload_' . bin2hex(random_bytes(4)) . '_' . $originalName;
        file_put_contents($source, $content);

        return new UploadedFile($source, $originalName, $mimeType, null, true);
    }

    public function testImportBackupAcceptsValidFileAndDispatchesMessage(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('paimon_moe', GenshinImpactService::class, ['.json'], PullBackupValidationResult::ok(42));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $file = $this->createUploadedFile('{"wish-counter-character-event":{"pulls":[]}}', 'paimon-backup.json');
        $request = Request::create(
            '/api/accounts/1/gameProfiles/10/pulls/import-backup',
            'POST',
            ['importer' => 'paimon_moe'],
            [],
            ['file' => $file]
        );

        $response = $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $bus);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame('backup_import', $payload['operation']['kind']);
        $this->assertSame(42, $payload['operation']['total']);

        $this->assertCount(1, $dispatched);
        $msg = $dispatched[0];
        $this->assertInstanceOf(ImportPullsBackupMessage::class, $msg);
        $this->assertSame($payload['operation']['id'], $msg->getOperationId());
        $this->assertSame(10, $msg->getGameProfileId());
        $this->assertSame('paimon_moe', $msg->getImporterKey());
        $this->assertTrue($msg->shouldDeleteFileAfter());
        $this->assertFileExists($msg->getFilePath());
    }

    public function testImportBackupRejectsInvalidFormatAndCleansUpTempFile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('paimon_moe', GenshinImpactService::class, ['.json'], PullBackupValidationResult::fail('Invalid paimon.moe schema.'));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $file = $this->createUploadedFile('{}', 'bad.json');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'paimon_moe'], [], ['file' => $file]);

        try {
            $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $bus);
            $this->fail('Expected BadRequestHttpException');
        } catch (BadRequestHttpException $e) {
            $this->assertSame('Invalid paimon.moe schema.', $e->getMessage());
        }

        // Verify temporary moved upload was deleted
        $tempDir = $this->tempProjectDir . '/var/tmp/hoyoverse_pull_imports';
        $remaining = is_dir($tempDir) ? array_diff(scandir($tempDir) ?: [], ['.', '..']) : [];
        $this->assertSame([], $remaining);
    }

    public function testImportBackupRejectsIncompatibleImporterAndWrongExtension(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $hsrImporter = $this->createFakeImporter('star_rail_station_csv', HonkaiStarRailService::class, ['.csv'], PullBackupValidationResult::ok(5));
        $importerManager = new PullBackupImporterManager([$hsrImporter]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $bus = $this->createStub(MessageBusInterface::class);

        $file = $this->createUploadedFile('uid,id', 'hsr.csv', 'text/csv');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'star_rail_station_csv'], [], ['file' => $file]);

        $this->expectException(BadRequestHttpException::class);
        $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $bus);
    }

    public function testImportBackupReturnsConflictWhenBackupAlreadyActive(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('paimon_moe', GenshinImpactService::class, ['.json'], PullBackupValidationResult::ok(10));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $tracker->startBackupImport(10, 1, 'paimon_moe', 'Paimon.moe (JSON)', 10);

        $file = $this->createUploadedFile('{}', 'backup.json');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'paimon_moe'], [], ['file' => $file]);

        $this->expectException(ConflictHttpException::class);
        $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $this->createStub(MessageBusInterface::class));
    }

    public function testOperationStatusReturnsOwnOperationAnd404ForOtherProfile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile10] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $profile11 = (new HoyoverseGameProfile())->setAccount($account)->setGameId(GenshinImpactService::GAME_ID);
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile11, 11);

        $controller = $this->createController();
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $op = $tracker->startBackupImport(10, 1, 'paimon_moe', 'Paimon.moe (JSON)', 15);

        $res = $controller->apiGameProfilePullsOperationStatus($account, $profile10, $op['id'], $tracker);
        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($op['id'], $data['operation']['id']);

        $this->expectException(NotFoundHttpException::class);
        $controller->apiGameProfilePullsOperationStatus($account, $profile11, $op['id'], $tracker);
    }

    public function testPullsOverviewIncludesImportersMercureTopicActiveOperationAndSetsCookie(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('paimon_moe', GenshinImpactService::class, ['.json'], PullBackupValidationResult::ok(10));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $activeOp = $tracker->startBackupImport(10, 1, 'paimon_moe', 'Paimon.moe (JSON)', 10);

        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('findProfileRows')->willReturn([]);

        $iconBuilder = $this->createStub(PullItemIconUrlBuilder::class);

        $subscribedTopics = [];
        $tokenFactory = $this->createStub(\Symfony\Component\Mercure\Jwt\TokenFactoryInterface::class);
        $tokenFactory->method('create')->willReturnCallback(
            static function (?array $subscribe = [], ?array $publish = [], array $additionalClaims = []) use (&$subscribedTopics): string {
                $subscribedTopics = $subscribe ?? [];

                return 'test-jwt-token';
            }
        );

        $hub = $this->createStub(HubInterface::class);
        $hub->method('getFactory')->willReturn($tokenFactory);
        $hub->method('getPublicUrl')->willReturn('https://hoyoverse.test/.well-known/mercure');

        $mercureAuth = new Authorization(new \Symfony\Component\Mercure\HubRegistry($hub));

        $req = Request::create('https://hoyoverse.test/api/accounts/1/gameProfiles/10/pulls/overview', 'GET');
        $res = $controller->apiGameProfilePullsOverview($req, $account, $profile, $pullRepo, self::calculator(), $iconBuilder, $importerManager, $tracker, $mercureAuth, self::timezoneResolver());

        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://games.test/hoyoverse/profiles/10/pulls/progress', $data['mercure_topic']);
        $this->assertSame(['https://games.test/hoyoverse/profiles/10/pulls/progress'], $subscribedTopics);
        $this->assertNotEmpty($req->attributes->get('_mercure_authorization_cookies'));
        $this->assertCount(1, $data['available_backup_importers']);
        $this->assertSame('paimon_moe', $data['available_backup_importers'][0]['key']);
        $this->assertSame($activeOp['id'], $data['active_operation']['id']);
    }

    public function testPullsOverviewComposesConfiguredAndDynamicBannersWithMergedMonthlyTimeline(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $pullRepo = $this->createMock(HoyoversePullRepository::class);
        $pullRepo->expects($this->once())->method('findProfileRows')->with($profile)->willReturn([
            $this->pullRow(1, '200', 3, 'Slingshot', '2026-04-01 10:00:00', 'Standard Wish'),
            $this->pullRow(2, '301', 3, 'Cool Steel', '2026-05-01 10:00:00'),
            $this->pullRow(3, '999', 5, 'Mystery', '2026-05-02 10:00:00', ''),
            $this->pullRow(4, '400', 5, 'Furina', '2026-06-01 10:00:00', 'Character Event Wish-2'),
            $this->pullRow(5, '200', 4, 'Bennett', '2026-06-02 10:00:00', 'Standard Wish'),
        ]);

        $req = Request::create('https://hoyoverse.test/api/accounts/1/gameProfiles/10/pulls/overview', 'GET');
        $res = $controller->apiGameProfilePullsOverview(
            $req,
            $account,
            $profile,
            $pullRepo,
            self::calculator(),
            $this->createIconBuilder(),
            new PullBackupImporterManager([]),
            new PullOperationTracker(new ArrayAdapter(), $this->createMercureHub(), 'games.test'),
            new Authorization(new \Symfony\Component\Mercure\HubRegistry($this->createMercureHub())),
            self::timezoneResolver(),
        );

        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(5, $data['total_pulls']);
        $this->assertSame(['301', '302', '200', '100', '500', '999'], array_column($data['banners'], 'key'));
        $this->assertSame([2, 0, 2, 0, 0, 1], array_column($data['banners'], 'total_pulls'), '400 joins 301');

        $characterEvent = $data['banners'][0];
        $this->assertSame(320, $characterEvent['currency_spent'], '160 per pull');
        $this->assertSame(['Furina'], array_column($characterEvent['recent_s_pulls'], 'name'));
        $this->assertSame('icon:furina', $characterEvent['recent_s_pulls'][0]['icon_url']);
        $this->assertSame('Furina', $characterEvent['oldest_s']['name']);
        $this->assertSame('icon:furina', $characterEvent['oldest_s']['icon_url']);
        $this->assertNull($data['banners'][1]['oldest_s'], 'No S on the weapon banner');

        $dynamic = $data['banners'][5];
        $this->assertSame('Banner 999', $dynamic['name']);
        $this->assertSame(['999'], $dynamic['gacha_types']);
        $this->assertSame(90, $dynamic['s_max_pity']);
        $this->assertSame(10, $dynamic['a_max_pity']);
        $this->assertSame('icon:mystery', $dynamic['recent_s_pulls'][0]['icon_url']);

        // Per-banner months merged and sorted
        $this->assertSame([
            ['month' => '2026-04', 'total' => 1, 's_count' => 0, 'a_count' => 0],
            ['month' => '2026-05', 'total' => 2, 's_count' => 1, 'a_count' => 0],
            ['month' => '2026-06', 'total' => 2, 's_count' => 1, 'a_count' => 1],
        ], $data['monthly_timeline']);
    }

    public function testPullsListComputesTheRequestedBannerThenFiltersAndPaginates(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $pullRepo = $this->createMock(HoyoversePullRepository::class);
        $pullRepo->expects($this->once())->method('findBannerRows')->with($profile, ['301', '400'])->willReturn([
            $this->pullRow(1, '301', 3, 'Cool Steel', '2026-06-01 10:00:00'),
            $this->pullRow(2, '301', 4, 'Bennett', '2026-06-01 10:01:00'),
            $this->pullRow(3, '400', 3, 'Slingshot', '2026-06-01 10:02:00'),
            $this->pullRow(4, '400', 5, 'Furina', '2026-06-01 10:03:00'),
            $this->pullRow(5, '301', 3, 'Raven Bow', '2026-06-01 10:04:00'),
            $this->pullRow(6, '301', 4, 'Xingqiu', '2026-06-01 10:05:00'),
        ]);

        $req = Request::create('/api/accounts/1/gameProfiles/10/pulls', 'GET', ['banner' => '301', 'rarities' => '5, 4', 'page' => '1', 'limit' => '1']);
        $res = $controller->apiGameProfilePullsList($req, $account, $profile, $pullRepo, self::calculator(), $this->createIconBuilder(), self::timezoneResolver());

        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('301', $data['banner']['key']);
        $this->assertSame(6, $data['banner']['total_pulls'], 'The summary covers the whole banner, not the page');
        $this->assertSame(2, $data['banner']['s_pity']);
        $this->assertSame('icon:furina', $data['banner']['recent_s_pulls'][0]['icon_url']);
        $this->assertSame(4, $data['banner']['oldest_s']['id']);
        $this->assertSame('icon:furina', $data['banner']['oldest_s']['icon_url']);
        $this->assertTrue($data['banner']['oldest_s']['has_room']);
        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(5, $data['limit'], 'Clamped to at least 5');
        $this->assertSame(['Xingqiu', 'Furina', 'Bennett'], array_column($data['items'], 'name'), 'Newest first');
        $this->assertSame([4, 4, 2], array_column($data['items'], 'pity'));
        $this->assertSame([6, 4, 2], array_column($data['items'], 'pull_number'));
        $this->assertSame('icon:xingqiu', $data['items'][0]['icon_url']);
    }

    public function testPullsListFallsBackToTheFirstBannerForAnUnknownKey(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $pullRepo = $this->createMock(HoyoversePullRepository::class);
        $pullRepo->expects($this->once())->method('findBannerRows')->with($profile, ['301', '400'])->willReturn([]);

        // 400 is a gacha type of the 301 group, not a banner key
        $req = Request::create('/api/accounts/1/gameProfiles/10/pulls', 'GET', ['banner' => '400']);
        $res = $controller->apiGameProfilePullsList($req, $account, $profile, $pullRepo, self::calculator(), $this->createIconBuilder(), self::timezoneResolver());

        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('301', $data['banner']['key']);
        $this->assertSame(0, $data['banner']['total_pulls']);
        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['total']);
    }

    public function testPullsListAcceptsRarityZeroForUnknownPulls(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $filler = ['origin' => PullOrigin::Filler, 'declaredPity' => null] + $this->pullRow(2, '301', 0, 'Unknown', '2026-06-01 09:59:59');
        $filler['itemId'] = null;
        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('findBannerRows')->willReturn([
            $filler,
            $this->pullRow(1, '301', 5, 'Furina', '2026-06-01 10:00:00') + ['origin' => PullOrigin::Recorded, 'declaredPity' => 2],
            $this->pullRow(3, '301', 3, 'Cool Steel', '2026-06-01 10:01:00'),
        ]);

        $list = function (string $rarities) use ($controller, $account, $profile, $pullRepo): array {
            $req = Request::create('/api/accounts/1/gameProfiles/10/pulls', 'GET', ['banner' => '301', 'rarities' => $rarities]);
            $res = $controller->apiGameProfilePullsList($req, $account, $profile, $pullRepo, self::calculator(), $this->createIconBuilder(), self::timezoneResolver());

            return json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);
        };

        $unknown = $list(' 0 , -1, abc,');
        $this->assertSame([2], array_column($unknown['items'], 'id'), 'Rarity 0 kept; empty, negative and non-numeric values skipped');
        $this->assertSame('filler', $unknown['items'][0]['origin']);
        $this->assertNull($unknown['items'][0]['pity']);
        $this->assertSame(1, $unknown['banner']['unknown_count']);

        $data = $list('0,5');
        $this->assertSame([1, 2], array_column($data['items'], 'id'));
        $this->assertSame(2, $data['items'][0]['pity'], 'Recorded pity 1 + the filler');
        $this->assertSame(2, $data['items'][0]['declared_pity']);
        $this->assertSame(1, $data['items'][0]['recorded_pity']);
        $this->assertArrayHasKey('has_room', $data['items'][0]);
        $this->assertArrayHasKey('open_window', $data['banner']);
    }

    public function testManualSuggestionsShape(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $catalog = $this->createStub(NanokaCatalogService::class);
        $catalog->method('getGiCatalog')->willReturn([
            'id_char' => [
                '10000089' => ['id' => '10000089', 'name' => 'Furina', 'rarity' => 5, 'icon' => 'x', 'item_type' => 'Character'],
                '10000032' => ['id' => '10000032', 'name' => 'Bennett', 'rarity' => 4, 'icon' => 'x', 'item_type' => 'Character'],
            ],
            'id_weapon' => [
                '11509' => ['id' => '11509', 'name' => 'Mistsplitter Reforged', 'rarity' => 5, 'icon' => 'x', 'item_type' => 'Weapon'],
            ],
        ]);
        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('findDistinctRecordedSItems')->willReturn([]);
        $pullRepo->method('findDistinctRecordedItemTypes')->willReturn(['Character', 'Weapon']);

        $req = Request::create('/api/accounts/1/gameProfiles/10/pulls/manual/suggestions', 'GET', ['banner' => '301']);
        $res = $controller->apiGameProfilePullsManualSuggestions($req, $account, $profile, new ManualPullSuggestionProvider($catalog, $pullRepo), $this->createIconBuilder());

        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['items', 'allow_free_text', 'item_types'], array_keys($data));
        $this->assertTrue($data['allow_free_text']);
        $this->assertSame([
            ['item_id' => '10000089', 'name' => 'Furina', 'item_type' => 'Character', 'item_type_label' => 'Character', 'rarity' => 5, 'icon_url' => 'icon:10000089'],
            ['item_id' => '11509', 'name' => 'Mistsplitter Reforged', 'item_type' => 'Weapon', 'item_type_label' => 'Weapon', 'rarity' => 5, 'icon_url' => 'icon:11509'],
        ], $data['items'], 'S items only');
        $this->assertSame([['value' => 'Character', 'label' => 'Character'], ['value' => 'Weapon', 'label' => 'Weapon']], $data['item_types']);

        $this->expectException(BadRequestHttpException::class);
        $controller->apiGameProfilePullsManualSuggestions(
            Request::create('/api/accounts/1/gameProfiles/10/pulls/manual/suggestions', 'GET', ['banner' => '400']),
            $account,
            $profile,
            new ManualPullSuggestionProvider($catalog, $pullRepo),
            $this->createIconBuilder(),
        );
    }

    public function testManualCreateReturns201AndRejectsAnUnknownBanner(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $request = new CreateManualPullRequest('301', 'Furina', '2026-06-01 10:00:00', 40);
        $created = (new HoyoversePull())->setGameProfile($profile);
        (new \ReflectionProperty(HoyoversePull::class, 'id'))->setValue($created, 99);

        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->once())->method('createManualS')
            ->with($profile, $this->isInstanceOf(GenshinImpactService::class), $request)
            ->willReturn($created);

        $res = $controller->apiGameProfilePullsManualCreate($account, $profile, $request, $service);
        $this->assertSame(Response::HTTP_CREATED, $res->getStatusCode());
        $this->assertSame(['success' => true, 'pull_id' => 99], json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR));

        $this->expectException(BadRequestHttpException::class);
        $controller->apiGameProfilePullsManualCreate($account, $profile, new CreateManualPullRequest('999', 'Furina', '2026-06-01 10:00:00', 40), $service);
    }

    public function testDeclarePityAndDeleteReturn404ForAPullOfAnotherProfile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $otherProfile = (new HoyoverseGameProfile())->setAccount($account)->setGameId(GenshinImpactService::GAME_ID);
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($otherProfile, 11);
        $controller = $this->createController();

        $foreignPull = (new HoyoversePull())->setGameProfile($otherProfile)->setOrigin(PullOrigin::Manual);
        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->never())->method('declarePity');
        $service->expects($this->never())->method('deleteManual');

        try {
            $controller->apiGameProfilePullsDeclarePity($account, $profile, $foreignPull, new DeclarePityRequest(50), $service);
            $this->fail('A pull of another profile must not be found');
        } catch (NotFoundHttpException) {
        }

        $this->expectException(NotFoundHttpException::class);
        $controller->apiGameProfilePullsManualDelete($account, $profile, $foreignPull, $service);
    }

    public function testDeclarePityAndDeleteDelegateToTheService(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController();

        $pull = (new HoyoversePull())->setGameProfile($profile)->setOrigin(PullOrigin::Manual);
        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->once())->method('declarePity')->with($profile, $this->isInstanceOf(GenshinImpactService::class), $pull, null);
        $service->expects($this->once())->method('deleteManual')->with($profile, $this->isInstanceOf(GenshinImpactService::class), $pull);

        $res = $controller->apiGameProfilePullsDeclarePity($account, $profile, $pull, new DeclarePityRequest(null), $service);
        $this->assertSame(['success' => true], json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR));
        $res = $controller->apiGameProfilePullsManualDelete($account, $profile, $pull, $service);
        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());
        $this->assertSame(['success' => true], json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    private static function calculator(): HoyoversePullStatsCalculator
    {
        return new HoyoversePullStatsCalculator(new PullGapPlanner());
    }

    private static function timezoneResolver(): HoyoverseServerTimezoneResolver
    {
        return new HoyoverseServerTimezoneResolver(new MockClock('2026-11-22 12:00:00 UTC'));
    }

    /**
     * A row as returned by HoyoversePullRepository::findProfileRows() / findBannerRows().
     *
     * @return array<string, mixed>
     */
    private function pullRow(int $id, string $gachaType, int $rarity, string $name, string $pulledAt, string $gachaName = 'Character Event Wish'): array
    {
        return [
            'id' => $id,
            'pullId' => sprintf('17000000000000%05d', $id),
            'gachaType' => $gachaType,
            'gachaName' => $gachaName,
            'itemType' => $rarity >= 5 ? 'Character' : 'Weapon',
            'itemId' => strtolower(str_replace(' ', '_', $name)),
            'name' => $name,
            'rarity' => $rarity,
            'pulledAt' => new \DateTimeImmutable($pulledAt),
        ];
    }

    private function createIconBuilder(): PullItemIconUrlBuilder
    {
        $iconBuilder = $this->createStub(PullItemIconUrlBuilder::class);
        $iconBuilder->method('enrichPullRows')->willReturnCallback(
            static fn (string|object $gameService, array $rows): array => array_map(
                static fn (array $row): array => $row + ['icon_url' => 'icon:'.$row['item_id']],
                $rows,
            )
        );

        return $iconBuilder;
    }

    private function createMercureHub(): HubInterface
    {
        $tokenFactory = $this->createStub(\Symfony\Component\Mercure\Jwt\TokenFactoryInterface::class);
        $tokenFactory->method('create')->willReturn('test-jwt-token');

        $hub = $this->createStub(HubInterface::class);
        $hub->method('getFactory')->willReturn($tokenFactory);
        $hub->method('getPublicUrl')->willReturn('https://hoyoverse.test/.well-known/mercure');

        return $hub;
    }
}
