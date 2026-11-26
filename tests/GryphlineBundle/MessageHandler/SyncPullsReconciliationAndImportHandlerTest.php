<?php

namespace App\Tests\GryphlineBundle\MessageHandler;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Message\Profile\ImportPullsBackupMessage;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\MessageHandler\Profile\ImportPullsBackupHandler;
use App\GryphlineBundle\MessageHandler\Profile\SyncPullsPageProfileHandler;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Pull\SyntheticPullReconcilerInterface;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Pull\ManualPullService;
use App\GryphlineBundle\Service\Pull\PullBackupImportService;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use App\GryphlineBundle\Service\Pull\PullSyntheticReconcilerManager;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SyncPullsReconciliationAndImportHandlerTest extends TestCase
{
    private function createProfile(int $id = 10): GryphlineGameProfile
    {
        $account = (new GryphlineAccount())->setAccountToken('acc-tok');
        $profile = (new GryphlineGameProfile())
            ->setAccount($account)
            ->setGameId(ArknightsEndfieldService::GAME_ID)
            ->setGameBiz(ArknightsEndfieldService::GAME_BIZ)
            ->setGameUid('90001')
            ->setNickname('Endministrator');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    private function createArknightsEndfieldMock(array $pullsToReturn, ?string $nextSeqId = null): ArknightsEndfieldService
    {
        $service = $this->getMockBuilder(ArknightsEndfieldService::class)
            ->setConstructorArgs([
                $this->createStub(HttpClientInterface::class),
                $this->createStub(HttpClientInterface::class),
                $this->createStub(SkportSignerService::class),
                $this->createStub(SkportAuthService::class),
                $this->createStub(SkportUtilsService::class),
                $this->createStub(DenormalizerInterface::class),
                $this->createStub(ObjectMapperInterface::class),
                new NullLogger(),
            ])
            ->onlyMethods(['fetchPullsPage'])
            ->getMock();

        $service->expects($this->once())
            ->method('fetchPullsPage')
            ->willReturnCallback(static function (
                GryphlineGameProfile $profile,
                string $category,
                string $u8Token,
                ?string $seqId,
                ?string &$outNextSeqId
            ) use ($pullsToReturn, $nextSeqId): array {
                $outNextSeqId = $nextSeqId;

                return $pullsToReturn;
            });

        return $service;
    }

    public function testSyncPullsHandlerPromotesSyntheticPullAndTracksProgress(): void
    {
        $profile = $this->createProfile(10);
        $ts = new \DateTimeImmutable('2026-06-01 12:00:00', new \DateTimeZone('UTC'));

        $incomingNew = (new GryphlinePull())->setSeqId('5003')->setPoolCategory('char')->setPoolType('E_CharacterGachaPoolType_Special')->setName('Laevatain')->setPulledAt($ts);
        $incomingPromoted = (new GryphlinePull())->setSeqId('5002')->setPoolCategory('char')->setPoolType('E_CharacterGachaPoolType_Special')->setName('Perlica')->setPulledAt($ts);
        $incomingExisting = (new GryphlinePull())->setSeqId('5001')->setPoolCategory('char')->setPoolType('E_CharacterGachaPoolType_Special')->setName('Chen')->setPulledAt($ts);

        $endfield = $this->createArknightsEndfieldMock([$incomingNew, $incomingPromoted, $incomingExisting], '5000');

        $profileRepo = $this->createStub(GryphlineGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('existsByProfileAndSeqId')->willReturnCallback(
            static fn (GryphlineGameProfile $p, string $seqId, string $cat): bool => $seqId === '5001' && $cat === 'char'
        );

        $reconciler = new class() implements SyntheticPullReconcilerInterface {
            public int $reconciledCount = 0;

            public function supportsGameService(string|GameInterface $gameService): bool
            {
                return true;
            }

            public function reconcile(GryphlineGameProfile $profile, GryphlinePull $incomingRealPull, GameInterface $gameService): ?GryphlinePull
            {
                if ($incomingRealPull->getSeqId() === '5002') {
                    $this->reconciledCount++;

                    return $incomingRealPull;
                }

                return null;
            }
        };

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->expects($this->once())->method('flush');

        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $operation = $tracker->startSyncOperation(10, 1, PullOperationKind::AUTO_SYNC, ['char' => 'Character Headhunting']);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        // Page 5001 already exists: last page of the banner, realigned after the flush ("char" is the Special banner)
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->once())
            ->method('realign')
            ->with($profile, $endfield, 'E_CharacterGachaPoolType_Special')
            ->willReturn(new FillerPlan());

        $handler = new SyncPullsPageProfileHandler(
            $profileRepo,
            new ServiceLocator([ArknightsEndfieldService::GAME_BIZ => static fn () => $endfield]),
            new NullLogger(),
            $this->createStub(UnifiedNotificationService::class),
            $this->createStub(SkportAuthService::class),
            $pullRepo,
            $em,
            $bus,
            new PullSyntheticReconcilerManager([$reconciler]),
            $tracker,
            new LockFactory(new InMemoryStore()),
            $manualPullService,
        );

        $handler(new SyncPullsPageProfileMessage(10, 'char', null, 'u8-token-test', $operation['id']));

        $this->assertCount(1, $persisted);
        $this->assertSame($incomingNew, $persisted[0]);
        $this->assertSame(1, $reconciler->reconciledCount);

        $finalOp = $tracker->getOperation($operation['id']);
        $this->assertSame('completed', $finalOp['status']);
        $this->assertSame(1, $finalOp['imported']);
        $this->assertSame(1, $finalOp['promoted']);
    }

    public function testSyncPullsHandlerRequeuesWhenProfileLockIsHeld(): void
    {
        $profile = $this->createProfile(10);
        $endfield = new ArknightsEndfieldService(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SkportSignerService::class),
            $this->createStub(SkportAuthService::class),
            $this->createStub(SkportUtilsService::class),
            $this->createStub(DenormalizerInterface::class),
            $this->createStub(ObjectMapperInterface::class),
            new NullLogger(),
        );

        $lockFactory = new LockFactory(new InMemoryStore());
        $heldLock = $lockFactory->createLock(PullBackupImportService::getProfileLockKey(10));
        $this->assertTrue($heldLock->acquire(false));

        try {
            $profileRepo = $this->createStub(GryphlineGameProfileRepository::class);
            $profileRepo->method('find')->willReturn($profile);

            $dispatched = [];
            $bus = $this->createMock(MessageBusInterface::class);
            $bus->expects($this->once())
                ->method('dispatch')
                ->willReturnCallback(static function (object $msg, array $stamps) use (&$dispatched): Envelope {
                    $dispatched[] = [$msg, $stamps];

                    return new Envelope($msg, $stamps);
                });

            $handler = new SyncPullsPageProfileHandler(
                $profileRepo,
                new ServiceLocator([ArknightsEndfieldService::GAME_BIZ => static fn () => $endfield]),
                new NullLogger(),
                $this->createStub(UnifiedNotificationService::class),
                $this->createStub(SkportAuthService::class),
                $this->createStub(GryphlinePullRepository::class),
                $this->createStub(EntityManagerInterface::class),
                $bus,
                new PullSyntheticReconcilerManager([]),
                new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test'),
                $lockFactory,
                $this->createStub(ManualPullService::class),
            );

            $message = new SyncPullsPageProfileMessage(10, 'char', null, 'u8-token-test', 'op-123');
            $handler($message);

            $this->assertCount(1, $dispatched);
            $this->assertSame($message, $dispatched[0][0]);
            $this->assertInstanceOf(DelayStamp::class, $dispatched[0][1][0]);
        } finally {
            $heldLock->release();
        }
    }

    private function createSyncHandler(ArknightsEndfieldService $endfield, GryphlineGameProfile $profile, MessageBusInterface $bus, ManualPullService $manualPullService): SyncPullsPageProfileHandler
    {
        $profileRepo = $this->createStub(GryphlineGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        return new SyncPullsPageProfileHandler(
            $profileRepo,
            new ServiceLocator([ArknightsEndfieldService::GAME_BIZ => static fn () => $endfield]),
            new NullLogger(),
            $this->createStub(UnifiedNotificationService::class),
            $this->createStub(SkportAuthService::class),
            $this->createStub(GryphlinePullRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $bus,
            new PullSyntheticReconcilerManager([]),
            new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test'),
            new LockFactory(new InMemoryStore()),
            $manualPullService,
        );
    }

    public function testSyncPullsHandlerDoesNotRealignBeforeTheLastPage(): void
    {
        $profile = $this->createProfile(10);
        $pull = (new GryphlinePull())->setSeqId('5003')->setPoolCategory('weapon')->setPoolType('E_WeaponGachaPoolType_Standard')->setName('JET')->setPulledAt(new \DateTimeImmutable());
        $endfield = $this->createArknightsEndfieldMock([$pull], '5003');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static fn (object $msg, array $stamps): Envelope => new Envelope($msg, $stamps));
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->never())->method('realign');

        $this->createSyncHandler($endfield, $profile, $bus, $manualPullService)(
            new SyncPullsPageProfileMessage(10, 'E_WeaponGachaPoolType_Standard', null, 'u8-token-test')
        );
    }

    public function testSyncPullsHandlerRealignsOnAnEmptyLastPage(): void
    {
        $profile = $this->createProfile(10);
        $endfield = $this->createArknightsEndfieldMock([], null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        // Earlier pages (separate messages) may have stored pulls: both banners of the weapon stream are realigned anyway
        $realigned = [];
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->exactly(2))
            ->method('realign')
            ->willReturnCallback(static function (GryphlineGameProfile $realignedProfile, object $game, string $bannerKey) use (&$realigned, $profile, $endfield): FillerPlan {
                self::assertSame($profile, $realignedProfile);
                self::assertSame($endfield, $game);
                $realigned[] = $bannerKey;

                return new FillerPlan();
            });

        $this->createSyncHandler($endfield, $profile, $bus, $manualPullService)(
            new SyncPullsPageProfileMessage(10, 'E_WeaponGachaPoolType_Standard', '4000', 'u8-token-test')
        );

        $this->assertSame(['E_WeaponGachaPoolType_Standard', 'E_WeaponGachaPoolType_Rerun'], $realigned);
    }

    public function testImportPullsBackupHandlerCompletesAndDeletesTempFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/gryphline_backup_cleanup_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($tempFile, '{}');

        $profile = $this->createProfile(10);
        $profileRepo = $this->createStub(GryphlineGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        $importService = $this->createMock(PullBackupImportService::class);
        $importService->expects($this->once())
            ->method('import')
            ->willReturn(new PullImportSummary('endfield_export', 'Endfield Export', 12, 9, 3));

        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $op = $tracker->startBackupImport(10, 1, 'endfield_export', 'Endfield Export', 12);

        $handler = new ImportPullsBackupHandler(
            $profileRepo,
            $importService,
            $tracker,
            $this->createStub(MessageBusInterface::class),
            new NullLogger(),
        );

        $handler(new ImportPullsBackupMessage($op['id'], 10, 'endfield_export', $tempFile, deleteFileAfter: true));

        $this->assertFileDoesNotExist($tempFile);
        $finalOp = $tracker->getOperation($op['id']);
        $this->assertSame('completed', $finalOp['status']);
        $this->assertSame(9, $finalOp['imported']);
        $this->assertSame(3, $finalOp['skipped']);
    }

    public function testImportPullsBackupHandlerRequeuesWithoutDeletingFileOnLockContention(): void
    {
        $tempFile = sys_get_temp_dir() . '/gryphline_backup_lock_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($tempFile, '{}');

        try {
            $profile = $this->createProfile(10);
            $profileRepo = $this->createStub(GryphlineGameProfileRepository::class);
            $profileRepo->method('find')->willReturn($profile);

            $importService = $this->createStub(PullBackupImportService::class);
            $importService->method('import')->willThrowException(new PullProfileLockedException('Locked'));

            $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
            $op = $tracker->startBackupImport(10, 1, 'endfield_export', 'Endfield Export', 12);

            $dispatched = [];
            $bus = $this->createMock(MessageBusInterface::class);
            $bus->expects($this->once())
                ->method('dispatch')
                ->willReturnCallback(static function (object $msg, array $stamps) use (&$dispatched): Envelope {
                    $dispatched[] = [$msg, $stamps];

                    return new Envelope($msg, $stamps);
                });

            $handler = new ImportPullsBackupHandler(
                $profileRepo,
                $importService,
                $tracker,
                $bus,
                new NullLogger(),
            );

            $handler(new ImportPullsBackupMessage($op['id'], 10, 'endfield_export', $tempFile, deleteFileAfter: true, lockAttempt: 0));

            $this->assertFileExists($tempFile);
            $this->assertCount(1, $dispatched);
            $this->assertInstanceOf(ImportPullsBackupMessage::class, $dispatched[0][0]);
            $this->assertSame(1, $dispatched[0][0]->getLockAttempt());
            $this->assertInstanceOf(DelayStamp::class, $dispatched[0][1][0]);
        } finally {
            @unlink($tempFile);
        }
    }

    private const string JOINT = 'E_CharacterGachaPoolType_Joint';
    private const string WEAPON = 'E_WeaponGachaPoolType_Standard';
    private const string WEAPON_RERUN = 'E_WeaponGachaPoolType_Rerun';
    private const string TOKEN = 'u8-token-test';

    /**
     * Endfield service with scripted record pages and a fake record meta API (the Special Headhunting banners).
     *
     * @param array<string, array{0: list<GryphlinePull>, 1: ?string}> $pages "poolId|seqId" => [pulls, next seq id]
     * @param list<array<string, mixed>> $metaTabs tabs of the record meta
     * @param list<array{category: string, token: string, seqId: ?string, poolId: ?string}> $fetches pages read, in order
     */
    private function createScriptedEndfield(array $pages, array $metaTabs, array &$fetches, int &$metaRequests): ArknightsEndfieldService
    {
        $metaClient = new MockHttpClient(static function (string $method, string $url) use ($metaTabs, &$metaRequests): MockResponse {
            $metaRequests++;
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://ef-webview.gryphline.com/api/record/char/meta?', $url);

            return new MockResponse(
                json_encode(['code' => 0, 'data' => ['tabs' => $metaTabs, 'beginnerPullCount' => 40]], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $service = self::getStubBuilder(ArknightsEndfieldService::class)
            ->setConstructorArgs([
                $this->createStub(HttpClientInterface::class),
                $metaClient,
                $this->createStub(SkportSignerService::class),
                $this->createStub(SkportAuthService::class),
                $this->createStub(SkportUtilsService::class),
                $this->createStub(DenormalizerInterface::class),
                $this->createStub(ObjectMapperInterface::class),
                new NullLogger(),
            ])
            ->onlyMethods(['fetchPullsPage'])
            ->getStub();

        $service->method('fetchPullsPage')->willReturnCallback(static function (
            GryphlineGameProfile $profile,
            string $category,
            string $u8Token,
            ?string $seqId,
            ?string &$nextSeqId,
            ?string $poolId = null,
        ) use ($pages, &$fetches): array {
            $fetches[] = ['category' => $category, 'token' => $u8Token, 'seqId' => $seqId, 'poolId' => $poolId];

            $key = ($poolId ?? '') . '|' . ($seqId ?? '');
            self::assertArrayHasKey($key, $pages, 'Unexpected record page ' . $key);
            [$pulls, $nextSeqId] = $pages[$key];

            return $pulls;
        });

        return $service;
    }

    /**
     * Sync handler whose bus, manual pull service and entity manager record what they receive.
     *
     * @param list<array{0: SyncPullsPageProfileMessage, 1: array<object>}> $dispatched
     * @param list<string> $realigned banner keys realigned
     * @param list<GryphlinePull> $persisted
     */
    private function createRecordingSyncHandler(
        ArknightsEndfieldService $endfield,
        GryphlineGameProfile $profile,
        PullOperationTracker $tracker,
        array &$dispatched,
        array &$realigned,
        array &$persisted,
        ?GryphlinePullRepository $pullRepo = null,
        ?LoggerInterface $logger = null,
    ): SyncPullsPageProfileHandler {
        $profileRepo = $this->createStub(GryphlineGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$dispatched): Envelope {
            $dispatched[] = [$message, $stamps];

            return new Envelope($message, $stamps);
        });

        $manualPullService = $this->createStub(ManualPullService::class);
        $manualPullService->method('realign')->willReturnCallback(static function (GryphlineGameProfile $realignedProfile, object $game, string $bannerKey) use (&$realigned, $profile, $endfield): FillerPlan {
            self::assertSame($profile, $realignedProfile);
            self::assertSame($endfield, $game);
            $realigned[] = $bannerKey;

            return new FillerPlan();
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        return new SyncPullsPageProfileHandler(
            $profileRepo,
            new ServiceLocator([ArknightsEndfieldService::GAME_BIZ => static fn () => $endfield]),
            $logger ?? new NullLogger(),
            $this->createStub(UnifiedNotificationService::class),
            $this->createStub(SkportAuthService::class),
            $pullRepo ?? $this->createStub(GryphlinePullRepository::class),
            $em,
            $bus,
            new PullSyntheticReconcilerManager([]),
            $tracker,
            new LockFactory(new InMemoryStore()),
            $manualPullService,
        );
    }

    private function createTracker(): PullOperationTracker
    {
        return new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
    }

    private static function pull(string $seqId, string $poolType, string $poolCategory = 'char', ?string $poolId = null): GryphlinePull
    {
        return (new GryphlinePull())
            ->setSeqId($seqId)
            ->setPoolCategory($poolCategory)
            ->setPoolType($poolType)
            ->setPoolId($poolId)
            ->setName('Pull ' . $seqId)
            ->setPulledAt(new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function metaTabs(string ...$jointPoolIds): array
    {
        $tabs = [['key' => 'special', 'label' => '', 'poolType' => 'E_CharacterGachaPoolType_Special']];
        foreach ($jointPoolIds as $poolId) {
            $tabs[] = ['key' => 'joint:' . $poolId, 'label' => '', 'poolType' => self::JOINT, 'poolId' => $poolId];
        }

        return $tabs;
    }

    /**
     * @param array<object> $stamps
     */
    private function assertNextPage(
        SyncPullsPageProfileMessage $message,
        array $stamps,
        ?string $seqId,
        string $poolId,
        array $pendingPoolIds,
        string $operationId,
    ): void {
        $this->assertSame(10, $message->getGameProfileId());
        $this->assertSame(self::JOINT, $message->getCategory());
        $this->assertSame($seqId, $message->getSeqId());
        $this->assertSame($poolId, $message->getPoolId());
        $this->assertSame($pendingPoolIds, $message->getPendingPoolIds());
        $this->assertSame(self::TOKEN, $message->getU8Token());
        $this->assertSame($operationId, $message->getOperationId());
        $this->assertInstanceOf(DelayStamp::class, $stamps[0] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jointBanner(PullOperationTracker $tracker, string $operationId): array
    {
        $operation = $tracker->getOperation($operationId);
        self::assertIsArray($operation);
        self::assertArrayHasKey(self::JOINT, $operation['banners']);

        return $operation['banners'][self::JOINT];
    }

    public function testJointStreamWithoutSpecialBannerFinishesWithoutReadingRecords(): void
    {
        $profile = $this->createProfile(10);
        $fetches = [];
        $metaRequests = 0;
        $endfield = $this->createScriptedEndfield([], self::metaTabs(), $fetches, $metaRequests);

        $tracker = $this->createTracker();
        $operation = $tracker->startSyncOperation(10, 1, PullOperationKind::AUTO_SYNC, [self::JOINT => 'Special Headhunting']);

        $dispatched = $realigned = $persisted = [];
        $handler = $this->createRecordingSyncHandler($endfield, $profile, $tracker, $dispatched, $realigned, $persisted);

        $handler(new SyncPullsPageProfileMessage(10, self::JOINT, null, self::TOKEN, $operation['id']));

        $this->assertSame(1, $metaRequests);
        $this->assertSame([], $fetches);
        $this->assertSame([], $dispatched);
        $this->assertSame([], $persisted);
        // The stream is over: its banner is realigned and its last page recorded
        $this->assertSame([self::JOINT], $realigned);
        $banner = self::jointBanner($tracker, $operation['id']);
        $this->assertSame('completed', $banner['status']);
        $this->assertSame(1, $banner['pages']);
        $this->assertSame(0, $banner['imported']);
        $this->assertSame('completed', $tracker->getOperation($operation['id'])['status']);
    }

    public function testJointStreamReadsItsBannersOneAfterAnother(): void
    {
        $profile = $this->createProfile(10);
        $fetches = [];
        $metaRequests = 0;
        $endfield = $this->createScriptedEndfield([
            'joint_1_2_2|' => [[self::pull('900', self::JOINT, poolId: 'joint_1_2_2'), self::pull('899', self::JOINT, poolId: 'joint_1_2_2')], '899'],
            // Last page of the first banner: no next seq id
            'joint_1_2_2|899' => [[self::pull('898', self::JOINT, poolId: 'joint_1_2_2')], null],
            'joint_1_3_1|' => [[self::pull('700', self::JOINT, poolId: 'joint_1_3_1')], null],
        ], self::metaTabs('joint_1_2_2', 'joint_1_3_1'), $fetches, $metaRequests);

        $tracker = $this->createTracker();
        $operation = $tracker->startSyncOperation(10, 1, PullOperationKind::AUTO_SYNC, [self::JOINT => 'Special Headhunting']);
        $operationId = $operation['id'];

        $dispatched = $realigned = $persisted = [];
        $handler = $this->createRecordingSyncHandler($endfield, $profile, $tracker, $dispatched, $realigned, $persisted);

        // 1. First page: the banners come from the meta, the first one is read
        $handler(new SyncPullsPageProfileMessage(10, self::JOINT, null, self::TOKEN, $operationId));

        $this->assertSame([['category' => self::JOINT, 'token' => self::TOKEN, 'seqId' => null, 'poolId' => 'joint_1_2_2']], $fetches);
        $this->assertCount(1, $dispatched);
        // The next page of the banner keeps its pool id and the banners still to read
        $this->assertNextPage($dispatched[0][0], $dispatched[0][1], '899', 'joint_1_2_2', ['joint_1_3_1'], $operationId);
        $this->assertSame([], $realigned);
        $this->assertSame('running', self::jointBanner($tracker, $operationId)['status']);

        // 2. Last page of the first banner: the second banner is queued from its first page
        $handler($dispatched[0][0]);

        $this->assertSame(['category' => self::JOINT, 'token' => self::TOKEN, 'seqId' => '899', 'poolId' => 'joint_1_2_2'], $fetches[1]);
        $this->assertCount(2, $dispatched);
        $this->assertNextPage($dispatched[1][0], $dispatched[1][1], null, 'joint_1_3_1', [], $operationId);
        $this->assertSame([], $realigned);
        $this->assertSame('running', self::jointBanner($tracker, $operationId)['status']);

        // 3. Last page of the last banner: the stream is over
        $handler($dispatched[1][0]);

        $this->assertSame(['category' => self::JOINT, 'token' => self::TOKEN, 'seqId' => null, 'poolId' => 'joint_1_3_1'], $fetches[2]);
        $this->assertCount(3, $fetches);
        $this->assertCount(2, $dispatched);
        $this->assertSame([self::JOINT], $realigned);
        // The banners are listed once, on the first page of the stream
        $this->assertSame(1, $metaRequests);

        $this->assertSame(['900', '899', '898', '700'], array_map(static fn (GryphlinePull $pull): string => (string) $pull->getSeqId(), $persisted));
        $banner = self::jointBanner($tracker, $operationId);
        $this->assertSame('completed', $banner['status']);
        $this->assertSame(3, $banner['pages']);
        $this->assertSame(4, $banner['imported']);
        $this->assertSame('completed', $tracker->getOperation($operationId)['status']);
    }

    public function testJointStreamGoesOnWithTheNextBannerAfterAnEmptyLastPage(): void
    {
        $profile = $this->createProfile(10);
        $fetches = [];
        $metaRequests = 0;
        $endfield = $this->createScriptedEndfield([
            'joint_1_2_2|' => [[self::pull('900', self::JOINT, poolId: 'joint_1_2_2')], '900'],
            'joint_1_2_2|900' => [[], null],
            'joint_1_3_1|' => [[], null],
        ], self::metaTabs('joint_1_2_2', 'joint_1_3_1'), $fetches, $metaRequests);

        $tracker = $this->createTracker();
        $operationId = $tracker->startSyncOperation(10, 1, PullOperationKind::AUTO_SYNC, [self::JOINT => 'Special Headhunting'])['id'];

        $dispatched = $realigned = $persisted = [];
        $handler = $this->createRecordingSyncHandler($endfield, $profile, $tracker, $dispatched, $realigned, $persisted);

        $handler(new SyncPullsPageProfileMessage(10, self::JOINT, null, self::TOKEN, $operationId));
        $this->assertCount(1, $dispatched);
        $this->assertNextPage($dispatched[0][0], $dispatched[0][1], '900', 'joint_1_2_2', ['joint_1_3_1'], $operationId);

        // Empty last page of the first banner: the second banner is queued, the stream goes on
        $handler($dispatched[0][0]);
        $this->assertCount(2, $dispatched);
        $this->assertNextPage($dispatched[1][0], $dispatched[1][1], null, 'joint_1_3_1', [], $operationId);
        $this->assertSame([], $realigned);
        $this->assertSame('running', self::jointBanner($tracker, $operationId)['status']);

        // Empty last page of the last banner: the stream is over
        $handler($dispatched[1][0]);
        $this->assertCount(2, $dispatched);
        $this->assertSame([self::JOINT], $realigned);
        $this->assertSame(1, $metaRequests);
        $this->assertSame(
            [[null, 'joint_1_2_2'], ['900', 'joint_1_2_2'], [null, 'joint_1_3_1']],
            array_map(static fn (array $fetch): array => [$fetch['seqId'], $fetch['poolId']], $fetches),
        );
        $banner = self::jointBanner($tracker, $operationId);
        $this->assertSame('completed', $banner['status']);
        $this->assertSame(3, $banner['pages']);
        $this->assertSame(1, $banner['imported']);
    }

    public function testJointStreamGoesOnWithTheNextBannerWhenItReachesStoredRecords(): void
    {
        $profile = $this->createProfile(10);
        $fetches = [];
        $metaRequests = 0;
        $endfield = $this->createScriptedEndfield([
            // 899 is already stored: the first banner is up to date even though the API has more pages
            'joint_1_2_2|' => [[self::pull('900', self::JOINT, poolId: 'joint_1_2_2'), self::pull('899', self::JOINT, poolId: 'joint_1_2_2'), self::pull('898', self::JOINT, poolId: 'joint_1_2_2')], '898'],
        ], self::metaTabs('joint_1_2_2', 'joint_1_3_1'), $fetches, $metaRequests);

        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('existsByProfileAndSeqId')->willReturnCallback(static fn (GryphlineGameProfile $p, string $seqId): bool => $seqId === '899');
        $pullRepo->method('findPoolTypeBySeqId')->willReturn(self::JOINT);

        $tracker = $this->createTracker();
        $operationId = $tracker->startSyncOperation(10, 1, PullOperationKind::AUTO_SYNC, [self::JOINT => 'Special Headhunting'])['id'];

        $dispatched = $realigned = $persisted = [];
        $handler = $this->createRecordingSyncHandler($endfield, $profile, $tracker, $dispatched, $realigned, $persisted, $pullRepo);

        $handler(new SyncPullsPageProfileMessage(10, self::JOINT, null, self::TOKEN, $operationId));

        $this->assertSame(['900'], array_map(static fn (GryphlinePull $pull): string => (string) $pull->getSeqId(), $persisted));
        $this->assertCount(1, $dispatched);
        $this->assertNextPage($dispatched[0][0], $dispatched[0][1], null, 'joint_1_3_1', [], $operationId);
        $this->assertSame([], $realigned);
        $this->assertSame('running', self::jointBanner($tracker, $operationId)['status']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function storedPoolTypeProvider(): iterable
    {
        yield 'stored as another weapon group' => [self::WEAPON, true];
        yield 'stored as the same weapon group' => [self::WEAPON_RERUN, false];
    }

    #[DataProvider('storedPoolTypeProvider')]
    public function testSyncStopsOnAStoredRecordAndWarnsWhenItsPoolTypeChanged(string $storedPoolType, bool $expectWarning): void
    {
        $profile = $this->createProfile(10);
        $fetches = [];
        $metaRequests = 0;
        $endfield = $this->createScriptedEndfield([
            '|' => [[
                self::pull('5003', self::WEAPON_RERUN, 'weapon'),
                self::pull('5002', self::WEAPON_RERUN, 'weapon'),
                self::pull('5001', self::WEAPON_RERUN, 'weapon'),
            ], '5001'],
        ], [], $fetches, $metaRequests);

        $lookups = [];
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('existsByProfileAndSeqId')->willReturnCallback(
            static fn (GryphlineGameProfile $p, string $seqId, ?string $category = null): bool => $seqId === '5002' && $category === 'weapon'
        );
        $pullRepo->method('findPoolTypeBySeqId')->willReturnCallback(
            static function (GryphlineGameProfile $p, string $seqId, string $category) use (&$lookups, $profile, $storedPoolType): ?string {
                self::assertSame($profile, $p);
                $lookups[] = [$seqId, $category];

                return $storedPoolType;
            }
        );

        $logs = [];
        $logger = new class($logs) extends AbstractLogger {
            /**
             * @param list<array{level: string, message: string}> $logs
             */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        $tracker = $this->createTracker();
        $operationId = $tracker->startSyncOperation(10, 1, PullOperationKind::AUTO_SYNC, [self::WEAPON => 'Arsenal Issue / RE-Factor Issue'])['id'];

        $dispatched = $realigned = $persisted = [];
        $handler = $this->createRecordingSyncHandler($endfield, $profile, $tracker, $dispatched, $realigned, $persisted, $pullRepo, $logger);

        $handler(new SyncPullsPageProfileMessage(10, self::WEAPON, null, self::TOKEN, $operationId));

        $this->assertSame(0, $metaRequests);
        $this->assertSame([['5002', 'weapon']], $lookups);
        // The sync stops on the stored record: the records after it are not stored, no next page is queued
        $this->assertSame(['5003'], array_map(static fn (GryphlinePull $pull): string => (string) $pull->getSeqId(), $persisted));
        $this->assertSame([], $dispatched);
        $this->assertSame([self::WEAPON, self::WEAPON_RERUN], $realigned);

        $warnings = array_values(array_filter($logs, static fn (array $log): bool => $log['level'] === 'warning'));
        if ($expectWarning) {
            $this->assertCount(1, $warnings);
            $this->assertStringContainsString('5002', $warnings[0]['message']);
            $this->assertStringContainsString('Endministrator', $warnings[0]['message']);
            $this->assertStringContainsString(self::WEAPON, $warnings[0]['message']);
            $this->assertStringContainsString(self::WEAPON_RERUN, $warnings[0]['message']);
        } else {
            $this->assertSame([], $warnings);
        }
    }

    public function testSyncPullsPageMessageQueuedBeforeThePoolPropertiesReadsWithoutPool(): void
    {
        // Serialized form of a message queued before poolId / pendingPoolIds existed (PHP serializer of Messenger)
        $class = SyncPullsPageProfileMessage::class;
        $legacyProperties = [
            'gameProfileId' => 10,
            'category' => self::JOINT,
            'seqId' => '899',
            'u8Token' => self::TOKEN,
            'operationId' => 'op-123',
        ];
        $payload = '';
        foreach ($legacyProperties as $name => $value) {
            $payload .= serialize("\0" . $class . "\0" . $name) . serialize($value);
        }
        $legacy = sprintf('O:%d:"%s":%d:{%s}', strlen($class), $class, count($legacyProperties), $payload);
        $this->assertStringNotContainsString('poolId', $legacy);
        $this->assertStringNotContainsString('pendingPoolIds', $legacy);

        $message = unserialize($legacy, ['allowed_classes' => [$class]]);

        $this->assertInstanceOf(SyncPullsPageProfileMessage::class, $message);
        $this->assertSame(10, $message->getGameProfileId());
        $this->assertSame(self::JOINT, $message->getCategory());
        $this->assertSame('899', $message->getSeqId());
        $this->assertSame(self::TOKEN, $message->getU8Token());
        $this->assertSame('op-123', $message->getOperationId());
        $this->assertNull($message->getPoolId());
        $this->assertSame([], $message->getPendingPoolIds());

        // A current message keeps its pools through the transport
        $current = unserialize(serialize(new SyncPullsPageProfileMessage(10, self::JOINT, '899', self::TOKEN, 'op-123', 'joint_1_2_2', [2 => 'joint_1_3_1'])));
        $this->assertSame('joint_1_2_2', $current->getPoolId());
        $this->assertSame(['joint_1_3_1'], $current->getPendingPoolIds());
    }
}
