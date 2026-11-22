<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\GamesBundle\Exception\PullProfileLockedException;
use App\HoyoverseBundle\Model\Pull\ImportedPullRecord;
use App\HoyoverseBundle\Model\Pull\PullBackupImporterInterface;
use App\GamesBundle\Model\Pull\PullBackupValidationResult;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\PullBackupImporterManager;
use App\HoyoverseBundle\Service\Pull\PullBackupImportService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class PullBackupImportServiceAndTrackerTest extends TestCase
{
    public function testImportServiceDeduplicatesExactAndLogicalMultisetAndHonorsDryRun(): void
    {
        $ts = new \DateTimeImmutable('2026-01-14 08:02:12', new \DateTimeZone('UTC'));
        $olderTs = new \DateTimeImmutable('2021-05-01 12:00:00', new \DateTimeZone('UTC'));

        $records = [
            // Exact pull_id already in DB -> skipped
            new ImportedPullRecord('paimon_301_20210501120000_000', '301', 'Weapon', '', 'Cool Steel', 3, $olderTs, null, true),
            // Overlapping real pull in DB (1 copy of Harbinger of Dawn exists in DB) -> first skipped, second imported
            new ImportedPullRecord('paimon_301_20260114080212_000', '301', 'Weapon', '', 'Harbinger of Dawn', 3, $ts, null, true),
            new ImportedPullRecord('paimon_301_20260114080212_001', '301', 'Weapon', '', 'Harbinger of Dawn', 3, $ts, null, true),
            // Brand new pull -> imported
            new ImportedPullRecord('paimon_301_20210501120000_001', '301', 'Character', '', 'Kamisato Ayaka', 5, $olderTs, null, true),
        ];

        $importer = new class($records) implements PullBackupImporterInterface {
            /** @param list<ImportedPullRecord> $records */
            public function __construct(private readonly array $records) {}
            public static function getKey(): string { return 'fake_gi'; }
            public function getLabel(): string { return 'Fake GI'; }
            public function getDescription(): string { return 'desc'; }
            public function getAcceptedExtensions(): array { return ['.json']; }
            public static function getSupportedGameServices(): array { return [GenshinImpactService::class]; }
            public function validate(string $filePath): PullBackupValidationResult { return PullBackupValidationResult::ok(count($this->records)); }
            public function parse(HoyoverseGameProfile $profile, string $filePath): array { return $this->records; }
        };

        $profile = $this->createProfileWithId(1, GenshinImpactService::GAME_ID);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getReference')->willReturn($profile);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        // Last batch, then the realign changes
        $em->expects($this->exactly(2))->method('flush');
        $em->expects($this->once())->method('clear');

        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('findExistingPullIdSet')->willReturn([
            'paimon_301_20210501120000_000' => true,
        ]);
        $pullRepo->method('findExistingLogicalPullCounts')->willReturn([
            HoyoversePullRepository::buildLogicalKey('301', $ts, 'Harbinger of Dawn') => 1,
        ]);

        $giService = new GenshinImpactService(new NullLogger());
        $locator = new ServiceLocator([
            GenshinImpactService::GAME_ID => static fn () => $giService,
        ]);

        $lockFactory = new LockFactory(new InMemoryStore());
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->once())->method('realignAll')
            ->with($profile, $giService)
            ->willReturnCallback(function () use ($lockFactory): void {
                $this->assertFalse($lockFactory->createLock(PullBackupImportService::getProfileLockKey(1))->acquire(false), 'Realign runs under the profile lock');
            });
        $service = new PullBackupImportService(
            $em,
            $pullRepo,
            new PullBackupImporterManager([$importer]),
            $lockFactory,
            $locator,
            $manualPullService,
        );

        $phases = [];
        $summary = $service->import(
            $profile,
            'fake_gi',
            '/dummy.json',
            onProgress: static function (PullImportProgress $p) use (&$phases): void {
                $phases[] = $p->phase;
            },
            dryRun: false,
        );

        $this->assertSame(4, $summary->total);
        $this->assertSame(2, $summary->imported);
        $this->assertSame(2, $summary->skipped);
        $this->assertCount(2, $persisted);
        $this->assertInstanceOf(HoyoversePull::class, $persisted[0]);
        $this->assertSame('Character Event Wish', $persisted[0]->getGachaName());
        $this->assertContains('parsing', $phases);
        $this->assertContains('importing', $phases);
    }

    public function testImportServiceDoesNotRealignOnDryRunOrWithoutImportedPulls(): void
    {
        $ts = new \DateTimeImmutable('2026-01-14 08:02:12', new \DateTimeZone('UTC'));
        $records = [new ImportedPullRecord('paimon_301_20260114080212_000', '301', 'Weapon', '', 'Cool Steel', 3, $ts, null, true)];
        $importer = new class($records) implements PullBackupImporterInterface {
            /** @param list<ImportedPullRecord> $records */
            public function __construct(private readonly array $records) {}
            public static function getKey(): string { return 'fake_gi'; }
            public function getLabel(): string { return 'Fake GI'; }
            public function getDescription(): string { return 'desc'; }
            public function getAcceptedExtensions(): array { return ['.json']; }
            public static function getSupportedGameServices(): array { return [GenshinImpactService::class]; }
            public function validate(string $filePath): PullBackupValidationResult { return PullBackupValidationResult::ok(count($this->records)); }
            public function parse(HoyoverseGameProfile $profile, string $filePath): array { return $this->records; }
        };

        $profile = $this->createProfileWithId(1, GenshinImpactService::GAME_ID);
        $giService = new GenshinImpactService(new NullLogger());
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->never())->method('realignAll');

        foreach ([[[], true], [['paimon_301_20260114080212_000' => true], false]] as [$existing, $dryRun]) {
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects($this->never())->method('flush');
            $pullRepo = $this->createStub(HoyoversePullRepository::class);
            $pullRepo->method('findExistingPullIdSet')->willReturn($existing);
            $service = new PullBackupImportService(
                $em,
                $pullRepo,
                new PullBackupImporterManager([$importer]),
                new LockFactory(new InMemoryStore()),
                new ServiceLocator([GenshinImpactService::GAME_ID => static fn () => $giService]),
                $manualPullService,
            );

            $summary = $service->import($profile, 'fake_gi', '/dummy.json', dryRun: $dryRun);
            $this->assertSame($dryRun ? 1 : 0, $summary->imported);
        }
    }

    public function testImportServiceThrowsWhenProfileLockIsHeld(): void
    {
        $importer = new class() implements PullBackupImporterInterface {
            public static function getKey(): string { return 'fake_gi'; }
            public function getLabel(): string { return 'Fake GI'; }
            public function getDescription(): string { return 'desc'; }
            public function getAcceptedExtensions(): array { return ['.json']; }
            public static function getSupportedGameServices(): array { return [GenshinImpactService::class]; }
            public function validate(string $filePath): PullBackupValidationResult { return PullBackupValidationResult::ok(1); }
            public function parse(HoyoverseGameProfile $profile, string $filePath): array { return []; }
        };

        $profile = $this->createProfileWithId(42, GenshinImpactService::GAME_ID);
        $store = new InMemoryStore();
        $lockFactory = new LockFactory($store);

        // Pre-acquire lock to simulate another concurrent pull operation
        $heldLock = $lockFactory->createLock(PullBackupImportService::getProfileLockKey(42), 60.0);
        $this->assertTrue($heldLock->acquire(false));

        $giService = new GenshinImpactService(new NullLogger());
        $service = new PullBackupImportService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(HoyoversePullRepository::class),
            new PullBackupImporterManager([$importer]),
            $lockFactory,
            new ServiceLocator([GenshinImpactService::GAME_ID => static fn () => $giService]),
            $this->createStub(ManualPullService::class),
        );

        $this->expectException(PullProfileLockedException::class);
        try {
            $service->import($profile, 'fake_gi', '/dummy.json', null, false, blockingLock: false);
        } finally {
            $heldLock->release();
        }
    }

    public function testOperationTrackerPublishesMercureUpdatesForBackupAndSync(): void
    {
        $cache = new ArrayAdapter();
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = [
                'topics' => $update->getTopics(),
                'data' => json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR),
                'private' => $update->isPrivate(),
            ];

            return 'urn:uuid:test';
        });

        $tracker = new PullOperationTracker($cache, $hub, 'hoyoverse.example.test');

        // 1. Sync operation lifecycle across 2 banners
        $syncOp = $tracker->startSyncOperation(7, 99, PullOperationKind::STOKEN_SYNC, [
            '301' => 'Character Event Wish',
            '200' => 'Standard Wish',
        ]);
        $this->assertSame('queued', $syncOp['status']);
        $this->assertNotNull($tracker->getActiveOperationForProfile(7));

        $afterPage1 = $tracker->recordSyncBannerPage($syncOp['id'], '301', 15, 5, false);
        $this->assertSame('running', $afterPage1['status']);
        $this->assertSame(15, $afterPage1['imported']);
        $this->assertSame(5, $afterPage1['promoted']);

        $afterBanner1Done = $tracker->recordSyncBannerPage($syncOp['id'], '301', 3, 0, true);
        $this->assertSame('running', $afterBanner1Done['status']);
        $this->assertSame(1, $afterBanner1Done['processed']);

        $afterAllDone = $tracker->recordSyncBannerPage($syncOp['id'], '200', 2, 0, true);
        $this->assertSame('completed', $afterAllDone['status']);
        $this->assertSame(100, $afterAllDone['percent']);
        $this->assertSame(20, $afterAllDone['imported']);
        $this->assertNull($tracker->getActiveOperationForProfile(7));

        // 2. Backup import lifecycle
        $bakOp = $tracker->startBackupImport(7, 99, 'paimon_moe', 'Paimon.moe (JSON)', 100);
        $this->assertTrue($tracker->hasActiveBackupImport(7));

        $tracker->updateBackupImportProgress($bakOp['id'], new PullImportProgress('importing', 100, 50, 40, 10));
        $current = $tracker->getOperation($bakOp['id']);
        $this->assertSame(50, $current['percent']);
        $this->assertSame(40, $current['imported']);

        // Verify Mercure broadcasts were private on profile topic
        $this->assertNotEmpty($published);
        $this->assertTrue($published[0]['private']);
        $this->assertSame(['https://hoyoverse.example.test/hoyoverse/profiles/7/pulls/progress'], $published[0]['topics']);
        $this->assertSame('pulls:operation_progress', $published[0]['data']['action']);
    }

    private function createProfileWithId(int $id, int $gameId): HoyoverseGameProfile
    {
        $profile = (new HoyoverseGameProfile())
            ->setGameId($gameId)
            ->setRegion('os_euro')
            ->setNickname('Tester');

        $ref = new \ReflectionProperty(HoyoverseGameProfile::class, 'id');
        $ref->setValue($profile, $id);

        return $profile;
    }
}
