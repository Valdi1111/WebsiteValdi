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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
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
        // Earlier pages (separate messages) may have stored pulls: the banner is realigned anyway
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->once())
            ->method('realign')
            ->with($profile, $endfield, 'E_WeaponGachaPoolType_Standard')
            ->willReturn(new FillerPlan());

        $this->createSyncHandler($endfield, $profile, $bus, $manualPullService)(
            new SyncPullsPageProfileMessage(10, 'E_WeaponGachaPoolType_Standard', '4000', 'u8-token-test')
        );
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
}
