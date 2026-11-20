<?php

namespace App\Tests\HoyoverseBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\PullItemIconUrlBuilder;
use App\HoyoverseBundle\Controller\ApiGameProfilesController;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\ImportPullsBackupMessage;
use App\HoyoverseBundle\Model\Pull\PullBackupImporterInterface;
use App\CoreBundle\Model\Pull\PullBackupValidationResult;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\PullBackupImporterManager;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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

    private function createController(HoyoverseAccount $account): ApiGameProfilesController
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
        $controller->setAccount($account);

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
        $controller = $this->createController($account);

        $importer = $this->createFakeImporter('paimon_moe', GenshinImpactService::class, ['.json'], PullBackupValidationResult::ok(42));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'hoyoverse.test');

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

        $response = $controller->apiGameProfilePullsImportBackup($request, $user, $profile, $importerManager, $tracker, $bus);

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
        $controller = $this->createController($account);

        $importer = $this->createFakeImporter('paimon_moe', GenshinImpactService::class, ['.json'], PullBackupValidationResult::fail('Invalid paimon.moe schema.'));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'hoyoverse.test');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $file = $this->createUploadedFile('{}', 'bad.json');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'paimon_moe'], [], ['file' => $file]);

        try {
            $controller->apiGameProfilePullsImportBackup($request, $user, $profile, $importerManager, $tracker, $bus);
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
        $controller = $this->createController($account);

        $hsrImporter = $this->createFakeImporter('star_rail_station_csv', HonkaiStarRailService::class, ['.csv'], PullBackupValidationResult::ok(5));
        $importerManager = new PullBackupImporterManager([$hsrImporter]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'hoyoverse.test');
        $bus = $this->createStub(MessageBusInterface::class);

        $file = $this->createUploadedFile('uid,id', 'hsr.csv', 'text/csv');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'star_rail_station_csv'], [], ['file' => $file]);

        $this->expectException(BadRequestHttpException::class);
        $controller->apiGameProfilePullsImportBackup($request, $user, $profile, $importerManager, $tracker, $bus);
    }

    public function testImportBackupReturnsConflictWhenBackupAlreadyActive(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController($account);

        $importer = $this->createFakeImporter('paimon_moe', GenshinImpactService::class, ['.json'], PullBackupValidationResult::ok(10));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'hoyoverse.test');
        $tracker->startBackupImport(10, 1, 'paimon_moe', 'Paimon.moe (JSON)', 10);

        $file = $this->createUploadedFile('{}', 'backup.json');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'paimon_moe'], [], ['file' => $file]);

        $this->expectException(ConflictHttpException::class);
        $controller->apiGameProfilePullsImportBackup($request, $user, $profile, $importerManager, $tracker, $this->createStub(MessageBusInterface::class));
    }

    public function testOperationStatusReturnsOwnOperationAnd404ForOtherProfile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile10] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $profile11 = (new HoyoverseGameProfile())->setAccount($account)->setGameId(GenshinImpactService::GAME_ID);
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile11, 11);

        $controller = $this->createController($account);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'hoyoverse.test');
        $op = $tracker->startBackupImport(10, 1, 'paimon_moe', 'Paimon.moe (JSON)', 15);

        $res = $controller->apiGameProfilePullsOperationStatus($user, $profile10, $op['id'], $tracker);
        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($op['id'], $data['operation']['id']);

        $this->expectException(NotFoundHttpException::class);
        $controller->apiGameProfilePullsOperationStatus($user, $profile11, $op['id'], $tracker);
    }

    public function testPullsOverviewIncludesImportersMercureTopicActiveOperationAndSetsCookie(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10, GenshinImpactService::GAME_ID);
        $controller = $this->createController($account);

        $importer = $this->createFakeImporter('paimon_moe', GenshinImpactService::class, ['.json'], PullBackupValidationResult::ok(10));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'hoyoverse.test');
        $activeOp = $tracker->startBackupImport(10, 1, 'paimon_moe', 'Paimon.moe (JSON)', 10);

        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('buildOverview')->willReturn([
            'total_pulls' => 0,
            'banners' => [],
            'monthly_timeline' => [],
        ]);

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
        $res = $controller->apiGameProfilePullsOverview($req, $user, $profile, $pullRepo, $iconBuilder, $importerManager, $tracker, $mercureAuth);

        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://hoyoverse.test/profiles/10/pulls/progress', $data['mercure_topic']);
        $this->assertSame(['https://hoyoverse.test/profiles/10/pulls/progress'], $subscribedTopics);
        $this->assertNotEmpty($req->attributes->get('_mercure_authorization_cookies'));
        $this->assertCount(1, $data['available_backup_importers']);
        $this->assertSame('paimon_moe', $data['available_backup_importers'][0]['key']);
        $this->assertSame($activeOp['id'], $data['active_operation']['id']);
    }
}
