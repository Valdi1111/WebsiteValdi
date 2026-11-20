<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\CoreBundle\Exception\PullProfileLockedException;
use App\HoyoverseBundle\Message\Profile\ImportPullsBackupMessage;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\CoreBundle\Model\Pull\PullImportSummary;
use App\CoreBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Model\Pull\SyntheticPullReconcilerInterface;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\Pull\PullBackupImportService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\PullSyntheticReconcilerManager;
use App\HoyoverseBundle\MessageHandler\Profile\ImportPullsBackupHandler;
use App\HoyoverseBundle\MessageHandler\Profile\SyncPullsPageProfileHandler;
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

class SyncPullsReconciliationAndImportHandlerTest extends TestCase
{
    public function testSyncPullsHandlerPromotesSyntheticPullAndTracksProgress(): void
    {
        $account = (new HoyoverseAccount())->setCookie('ltoken_v2=abc; ltuid_v2=123');
        $profile = (new HoyoverseGameProfile())
            ->setAccount($account)
            ->setGameId(GenshinImpactService::GAME_ID)
            ->setGameBiz(GenshinImpactService::GAME_BIZ)
            ->setNickname('Traveler');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 10);

        $ts = new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('UTC'));
        $incomingNew = (new HoyoversePull())->setPullId('1790000000000000099')->setGachaType('301')->setName('Jean')->setPulledAt($ts);
        $incomingPromoted = (new HoyoversePull())->setPullId('1790000000000000098')->setGachaType('301')->setName('Cool Steel')->setPulledAt($ts);
        $incomingExistingReal = (new HoyoversePull())->setPullId('1790000000000000097')->setGachaType('301')->setName('Raven Bow')->setPulledAt($ts);

        $giService = $this->getMockBuilder(GenshinImpactService::class)
            ->setConstructorArgs([new NullLogger()])
            ->onlyMethods(['fetchPullsPage'])
            ->getMock();
        $giService->expects($this->once())
            ->method('fetchPullsPage')
            ->willReturn([$incomingNew, $incomingPromoted, $incomingExistingReal]);

        $profileRepo = $this->createStub(HoyoverseGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('existsByProfileAndPullId')->willReturnCallback(
            static fn (HoyoverseGameProfile $p, string $id): bool => $id === '1790000000000000097'
        );

        $reconciler = new class() implements SyntheticPullReconcilerInterface {
            public int $reconciledCount = 0;
            public function supportsGameService(string|GameInterface $gameService): bool { return true; }
            public function reconcile(HoyoverseGameProfile $profile, HoyoversePull $incomingRealPull, GameInterface $gameService): ?HoyoversePull
            {
                if ($incomingRealPull->getPullId() === '1790000000000000098') {
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

        $hub = $this->createStub(HubInterface::class);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $hub, 'hoyoverse.test');
        $operation = $tracker->startSyncOperation(10, 1, PullOperationKind::STOKEN_SYNC, ['301' => 'Character Event Wish']);

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $notificationService = $this->createStub(UnifiedNotificationService::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch'); // Stopped on existing real pull

        $handler = new SyncPullsPageProfileHandler(
            $profileRepo,
            $cookieUtils,
            new ServiceLocator([
                GenshinImpactService::GAME_ID => static fn () => $giService,
            ]),
            new NullLogger(),
            $notificationService,
        );
        $handler->setEntityManager($em);
        $handler->setPullRepository($pullRepo);
        $handler->setBus($bus);
        $handler->setSyntheticReconcilerManager(new PullSyntheticReconcilerManager([$reconciler]));
        $handler->setOperationTracker($tracker);
        $handler->setLockFactory(new LockFactory(new InMemoryStore()));

        $handler(new SyncPullsPageProfileMessage(10, '301', '0', 'test-authkey', [], $operation['id']));

        // Only $incomingNew was persisted; $incomingPromoted was reconciled; $incomingExistingReal halted the loop
        $this->assertCount(1, $persisted);
        $this->assertSame($incomingNew, $persisted[0]);
        $this->assertSame(1, $reconciler->reconciledCount);

        $finalOp = $tracker->getOperation($operation['id']);
        $this->assertSame('completed', $finalOp['status']);
        $this->assertSame(1, $finalOp['imported']);
        $this->assertSame(1, $finalOp['promoted']);
    }

    public function testImportPullsBackupHandlerCompletesAndDeletesTempFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_backup_cleanup_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($tempFile, '{}');

        $profile = new HoyoverseGameProfile();
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 5);

        $profileRepo = $this->createStub(HoyoverseGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        $importService = $this->createMock(PullBackupImportService::class);
        $importService->expects($this->once())
            ->method('import')
            ->willReturn(new PullImportSummary('paimon_moe', 'Paimon.moe (JSON)', 10, 8, 2));

        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'hoyoverse.test');
        $op = $tracker->startBackupImport(5, 1, 'paimon_moe', 'Paimon.moe (JSON)', 10);

        $bus = $this->createStub(MessageBusInterface::class);
        $handler = new ImportPullsBackupHandler($profileRepo, $importService, $tracker, $bus, new NullLogger());
        $handler(new ImportPullsBackupMessage($op['id'], 5, 'paimon_moe', $tempFile, deleteFileAfter: true));

        $this->assertFileDoesNotExist($tempFile);
        $finalOp = $tracker->getOperation($op['id']);
        $this->assertSame('completed', $finalOp['status']);
        $this->assertSame(8, $finalOp['imported']);
        $this->assertSame(2, $finalOp['skipped']);
    }

    public function testImportPullsBackupHandlerRequeuesWithoutDeletingFileOnLockContention(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_backup_lock_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($tempFile, '{}');

        try {
            $profile = new HoyoverseGameProfile();
            (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 5);

            $profileRepo = $this->createStub(HoyoverseGameProfileRepository::class);
            $profileRepo->method('find')->willReturn($profile);

            $importService = $this->createStub(PullBackupImportService::class);
            $importService->method('import')->willThrowException(new PullProfileLockedException('Locked'));

            $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'hoyoverse.test');
            $op = $tracker->startBackupImport(5, 1, 'paimon_moe', 'Paimon.moe (JSON)', 10);

            $dispatched = [];
            $bus = $this->createMock(MessageBusInterface::class);
            $bus->expects($this->once())
                ->method('dispatch')
                ->willReturnCallback(static function (object $msg, array $stamps) use (&$dispatched): Envelope {
                    $dispatched[] = [$msg, $stamps];

                    return new Envelope($msg, $stamps);
                });

            $handler = new ImportPullsBackupHandler($profileRepo, $importService, $tracker, $bus, new NullLogger());
            $handler(new ImportPullsBackupMessage($op['id'], 5, 'paimon_moe', $tempFile, deleteFileAfter: true, lockAttempt: 0));

            // Temp file must still exist for the retried job
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
