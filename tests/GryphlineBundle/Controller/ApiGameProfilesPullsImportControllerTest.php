<?php

namespace App\Tests\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Pull\PullBackupValidationResult;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\GryphlineBundle\Controller\ApiGameProfilesController;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Message\Profile\ImportPullsBackupMessage;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Model\Pull\PullBackupImporterInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\EndfieldService;
use App\GryphlineBundle\Service\Pull\PullBackupImporterManager;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
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
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiGameProfilesPullsImportControllerTest extends TestCase
{
    private string $tempProjectDir;

    protected function setUp(): void
    {
        $this->tempProjectDir = sys_get_temp_dir() . '/valdi_gryph_ctrl_test_' . bin2hex(random_bytes(6));
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

    /**
     * @return array{GryphlineAccount, GryphlineGameProfile}
     */
    private function createAccountAndProfile(User $user, int $profileId = 10): array
    {
        $account = (new GryphlineAccount())->setUser($user);
        $profile = (new GryphlineGameProfile())
            ->setAccount($account)
            ->setGameId(EndfieldService::GAME_ID)
            ->setGameBiz(EndfieldService::GAME_BIZ)
            ->setNickname('Endministrator');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, $profileId);

        return [$account, $profile];
    }

    private function createEndfieldService(): EndfieldService
    {
        return new EndfieldService(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SkportSignerService::class),
            $this->createStub(SkportAuthService::class),
            $this->createStub(SkportUtilsService::class),
            $this->createStub(DenormalizerInterface::class),
            $this->createStub(ObjectMapperInterface::class),
            new NullLogger(),
        );
    }

    private function createController(): ApiGameProfilesController
    {
        $endfield = $this->createEndfieldService();
        $locator = new ServiceLocator([
            EndfieldService::GAME_BIZ => static fn () => $endfield,
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

    /**
     * @param list<string> $extensions
     */
    private function createFakeImporter(
        string $key,
        array $extensions,
        PullBackupValidationResult $validationResult,
    ): PullBackupImporterInterface {
        return new class($key, $extensions, $validationResult) implements PullBackupImporterInterface {
            private static string $staticKey = '';

            /**
             * @param list<string> $extensions
             */
            public function __construct(
                private readonly string $key,
                private readonly array $extensions,
                private readonly PullBackupValidationResult $validationResult,
            ) {
                self::$staticKey = $key;
            }

            public static function getKey(): string
            {
                return self::$staticKey;
            }

            public function getLabel(): string
            {
                return 'Label ' . $this->key;
            }

            public function getDescription(): string
            {
                return 'Desc';
            }

            public function getAcceptedExtensions(): array
            {
                return $this->extensions;
            }

            public static function getSupportedGameServices(): array
            {
                return [EndfieldService::class];
            }

            public function validate(string $filePath): PullBackupValidationResult
            {
                return $this->validationResult;
            }

            public function parse(GryphlineGameProfile $profile, string $filePath): array
            {
                return [];
            }
        };
    }

    private function createUploadedFile(string $content, string $originalName): UploadedFile
    {
        $source = $this->tempProjectDir . '/upload_' . bin2hex(random_bytes(4)) . '_' . $originalName;
        file_put_contents($source, $content);

        return new UploadedFile($source, $originalName, 'application/json', null, true);
    }

    public function testPullsSyncStartsOperationAndDispatchesBannerMessages(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(4))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $response = $controller->apiGameProfilePullsSync($user, $account, $profile, $bus, $tracker);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame('auto_sync', $payload['operation']['kind']);
        $this->assertSame(4, $payload['operation']['total']);

        foreach ($dispatched as $message) {
            $this->assertInstanceOf(SyncPullsPageProfileMessage::class, $message);
            $this->assertSame($payload['operation']['id'], $message->getOperationId());
        }
    }

    public function testPullsOverviewSetsMercureCookieAndReturnsProgressMetadata(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('buildOverview')->willReturn([
            'total_pulls' => 0,
            'banners' => [],
            'monthly_timeline' => [],
        ]);

        $subscribedTopics = [];
        $tokenFactory = $this->createStub(TokenFactoryInterface::class);
        $tokenFactory->method('create')->willReturnCallback(
            static function (?array $subscribe = [], ?array $publish = [], array $additionalClaims = []) use (&$subscribedTopics): string {
                $subscribedTopics = $subscribe ?? [];

                return 'fake-jwt';
            }
        );

        $hub = $this->createStub(HubInterface::class);
        $hub->method('getPublicUrl')->willReturn('https://gryphline.test/.well-known/mercure');
        $hub->method('getFactory')->willReturn($tokenFactory);

        $authorization = new Authorization(new HubRegistry($hub));
        $tracker = new PullOperationTracker(new ArrayAdapter(), $hub, 'games.test');
        $activeOp = $tracker->startSyncOperation(10, 1, \App\GamesBundle\Model\Pull\PullOperationKind::AUTO_SYNC, ['char' => 'Character']);

        $request = Request::create('https://gryphline.test/api/accounts/1/gameProfiles/10/pulls/overview', 'GET');
        $response = $controller->apiGameProfilePullsOverview(
            $request,
            $account,
            $profile,
            $pullRepo,
            $this->createStub(PullItemIconUrlBuilder::class),
            new PullBackupImporterManager([]),
            $tracker,
            $authorization,
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $payload['available_backup_importers']);
        $this->assertSame('https://games.test/gryphline/profiles/10/pulls/progress', $payload['mercure_topic']);
        $this->assertSame($activeOp['id'], $payload['active_operation']['id']);
        $this->assertNotEmpty($request->attributes->get('_mercure_authorization_cookies'));
    }

    public function testImportBackupAcceptsValidFileAndDispatchesMessage(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('endfield_export', ['.json'], PullBackupValidationResult::ok(25));
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

        $file = $this->createUploadedFile('{"pulls":[]}', 'endfield.json');
        $request = Request::create(
            '/api/accounts/1/gameProfiles/10/pulls/import-backup',
            'POST',
            ['importer' => 'endfield_export'],
            [],
            ['file' => $file]
        );

        $response = $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $bus);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame('backup_import', $payload['operation']['kind']);
        $this->assertSame(25, $payload['operation']['total']);

        $this->assertCount(1, $dispatched);
        $msg = $dispatched[0];
        $this->assertInstanceOf(ImportPullsBackupMessage::class, $msg);
        $this->assertSame($payload['operation']['id'], $msg->getOperationId());
        $this->assertFileExists($msg->getFilePath());
    }

    public function testImportBackupRejectsInvalidFormatAndCleansUpTempFile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('endfield_export', ['.json'], PullBackupValidationResult::fail('Invalid export schema.'));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $file = $this->createUploadedFile('{}', 'bad.json');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'endfield_export'], [], ['file' => $file]);

        try {
            $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $bus);
            $this->fail('Expected BadRequestHttpException');
        } catch (BadRequestHttpException $e) {
            $this->assertSame('Invalid export schema.', $e->getMessage());
        }

        $tempDir = $this->tempProjectDir . '/var/tmp/gryphline_pull_imports';
        $remaining = is_dir($tempDir) ? array_diff(scandir($tempDir) ?: [], ['.', '..']) : [];
        $this->assertSame([], $remaining);
    }

    public function testImportBackupReturnsConflictWhenAnotherBackupIsRunning(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('endfield_export', ['.json'], PullBackupValidationResult::ok(10));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $tracker->startBackupImport(10, 1, 'endfield_export', 'Endfield Export', 10);

        $file = $this->createUploadedFile('{}', 'endfield.json');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'endfield_export'], [], ['file' => $file]);

        $this->expectException(ConflictHttpException::class);
        $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $this->createStub(MessageBusInterface::class));
    }

    public function testOperationStatusReturnsSnapshotOrNotFoundForOtherProfile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $op = $tracker->startBackupImport(10, 1, 'endfield_export', 'Endfield Export', 10);
        $otherOp = $tracker->startBackupImport(99, 1, 'endfield_export', 'Endfield Export', 10);

        $okResponse = $controller->apiGameProfilePullsOperationStatus($account, $profile, $op['id'], $tracker);
        $this->assertSame(Response::HTTP_OK, $okResponse->getStatusCode());
        $payload = json_decode((string) $okResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($op['id'], $payload['operation']['id']);

        $this->expectException(NotFoundHttpException::class);
        $controller->apiGameProfilePullsOperationStatus($account, $profile, $otherOp['id'], $tracker);
    }
}
