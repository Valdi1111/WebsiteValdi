<?php

namespace App\Tests\GryphlineBundle\Service\Pull;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullBackupValidationResult;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Model\Pull\ImportedPullRecord;
use App\GryphlineBundle\Model\Pull\PullBackupImporterInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\EndfieldService;
use App\GryphlineBundle\Service\Pull\PullBackupImporterManager;
use App\GryphlineBundle\Service\Pull\PullBackupImportService;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
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
use Symfony\Component\Mercure\Update;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GryphlinePullServicesTest extends TestCase
{
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

    private function createProfile(int $id = 12): GryphlineGameProfile
    {
        $profile = (new GryphlineGameProfile())
            ->setGameId(EndfieldService::GAME_ID)
            ->setGameBiz(EndfieldService::GAME_BIZ)
            ->setNickname('Endministrator');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    /**
     * @param list<ImportedPullRecord> $records
     */
    private function createDummyImporter(array $records): PullBackupImporterInterface
    {
        return new class($records) implements PullBackupImporterInterface {
            /**
             * @param list<ImportedPullRecord> $records
             */
            public function __construct(private readonly array $records)
            {
            }

            public static function getKey(): string
            {
                return 'endfield_test_export';
            }

            public function getLabel(): string
            {
                return 'Endfield Test Export (JSON)';
            }

            public function getDescription(): string
            {
                return 'Test backup exporter for Arknights: Endfield.';
            }

            public function getAcceptedExtensions(): array
            {
                return ['.json'];
            }

            public static function getSupportedGameServices(): array
            {
                return [EndfieldService::class];
            }

            public function validate(string $filePath): PullBackupValidationResult
            {
                return PullBackupValidationResult::ok(count($this->records));
            }

            public function parse(GryphlineGameProfile $profile, string $filePath): array
            {
                return $this->records;
            }
        };
    }

    public function testOperationTrackerPublishesPrivateMercureUpdatesForSyncAndBackup(): void
    {
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = $update;

            return 'urn:uuid:test';
        });

        $tracker = new PullOperationTracker(new ArrayAdapter(), $hub, 'games.test');

        $this->assertSame('https://games.test/gryphline/profiles/12/pulls/progress', $tracker->getProfileTopic(12));

        // 1. Multi-banner sync lifecycle
        $syncOp = $tracker->startSyncOperation(12, 1, PullOperationKind::AUTO_SYNC, [
            'char' => 'Character Headhunting',
            'weapon' => 'Weapon Headhunting',
        ]);
        $this->assertSame('queued', $syncOp['status']);
        $this->assertSame('auto_sync', $syncOp['kind']);
        $this->assertSame($syncOp['id'], $tracker->getActiveOperationForProfile(12)['id']);

        $afterCharPage = $tracker->recordSyncBannerPage($syncOp['id'], 'char', 10, 1, false, 'Character Headhunting');
        $this->assertSame('running', $afterCharPage['status']);
        $this->assertSame(10, $afterCharPage['imported']);
        $this->assertSame(1, $afterCharPage['promoted']);

        $afterCharDone = $tracker->recordSyncBannerPage($syncOp['id'], 'char', 2, 0, true, 'Character Headhunting');
        $this->assertSame('running', $afterCharDone['status']);
        $this->assertSame(1, $afterCharDone['processed']);

        $afterAllDone = $tracker->recordSyncBannerPage($syncOp['id'], 'weapon', 5, 0, true, 'Weapon Headhunting');
        $this->assertSame('completed', $afterAllDone['status']);
        $this->assertSame(2, $afterAllDone['processed']);
        $this->assertSame(17, $afterAllDone['imported']);
        $this->assertSame(1, $afterAllDone['promoted']);

        // 2. Backup import lifecycle
        $backupOp = $tracker->startBackupImport(12, 1, 'endfield_test_export', 'Endfield Test Export', 20);
        $this->assertTrue($tracker->hasActiveBackupImport(12));

        $tracker->updateBackupImportProgress($backupOp['id'], new PullImportProgress('importing', 20, 10, 8, 2));
        $completedBackup = $tracker->completeBackupImport(
            $backupOp['id'],
            new PullImportSummary('endfield_test_export', 'Endfield Test Export', 20, 15, 5)
        );
        $this->assertSame('completed', $completedBackup['status']);
        $this->assertSame(15, $completedBackup['imported']);
        $this->assertSame(5, $completedBackup['skipped']);
        $this->assertFalse($tracker->hasActiveBackupImport(12));

        // Verify all published Mercure updates target the private profile topic
        $this->assertNotEmpty($published);
        foreach ($published as $update) {
            $this->assertTrue($update->isPrivate());
            $this->assertSame(['https://games.test/gryphline/profiles/12/pulls/progress'], $update->getTopics());
        }
    }

    public function testImporterManagerReturnsMetadataAndHandlesEmptyRegistry(): void
    {
        $endfield = $this->createEndfieldService();

        $emptyManager = new PullBackupImporterManager([]);
        $this->assertSame([], $emptyManager->getMetadataForGameService($endfield));
        $this->assertNull($emptyManager->getByKey('missing'));

        $importer = $this->createDummyImporter([]);
        $manager = new PullBackupImporterManager([$importer]);
        $metadata = $manager->getMetadataForGameService($endfield);

        $this->assertCount(1, $metadata);
        $this->assertSame('endfield_test_export', $metadata[0]['key']);
        $this->assertSame(['.json'], $metadata[0]['accepted_extensions']);
        $this->assertTrue($manager->supportsGameService($importer, $endfield));
    }

    public function testBackupImportServiceDeduplicatesBySeqIdAndSyntheticLogicalMultiset(): void
    {
        $profile = $this->createProfile(12);
        $endfield = $this->createEndfieldService();
        $ts = new \DateTimeImmutable('2026-06-01 12:00:00', new \DateTimeZone('UTC'));

        $records = [
            // Exact duplicate (already in DB)
            new ImportedPullRecord('1001', 'E_CharacterGachaPoolType_Special', 'char', 'pool_1', 'Chartered', 'chr_1', 'Laevatain', 6, $ts, '1780315200000', null, false),
            // New real pull
            new ImportedPullRecord('1002', 'E_CharacterGachaPoolType_Special', 'char', 'pool_1', 'Chartered', 'chr_2', 'Perlica', 5, $ts, '1780315200000', null, false),
            // Synthetic pull matching an existing official pull (should be skipped)
            new ImportedPullRecord('ext_1', 'E_CharacterGachaPoolType_Special', 'char', 'pool_1', 'Chartered', 'chr_1', 'Laevatain', 6, $ts, '1780315200000', null, true),
            // Second synthetic pull with same logical key (exceeds existing count 1 -> imported)
            new ImportedPullRecord('ext_2', 'E_CharacterGachaPoolType_Special', 'char', 'pool_1', 'Chartered', 'chr_1', 'Laevatain', 6, $ts, '1780315200000', null, true),
        ];

        $importer = $this->createDummyImporter($records);
        $manager = new PullBackupImporterManager([$importer]);

        $existingSeqKey = GryphlinePullRepository::buildSeqKey('char', '1001');
        $existingLogicalKey = GryphlinePullRepository::buildLogicalKey('E_CharacterGachaPoolType_Special', $ts, 'Laevatain');

        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findExistingSeqKeySet')->willReturn([$existingSeqKey => true]);
        $pullRepo->method('findExistingLogicalPullCounts')->willReturn([$existingLogicalKey => 1]);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->expects($this->once())->method('flush');
        $em->expects($this->once())->method('clear');

        $service = new PullBackupImportService(
            $em,
            $pullRepo,
            $manager,
            new LockFactory(new InMemoryStore()),
            new ServiceLocator([EndfieldService::GAME_BIZ => static fn () => $endfield]),
        );

        $progressEvents = [];
        $summary = $service->import(
            $profile,
            'endfield_test_export',
            '/tmp/unused.json',
            static function (PullImportProgress $progress) use (&$progressEvents): void {
                $progressEvents[] = $progress;
            },
            dryRun: false
        );

        $this->assertSame(4, $summary->total);
        $this->assertSame(2, $summary->imported);
        $this->assertSame(2, $summary->skipped);
        $this->assertCount(2, $persisted);
        $this->assertInstanceOf(GryphlinePull::class, $persisted[0]);
        $this->assertSame('1002', $persisted[0]->getSeqId());
        $this->assertSame('ext_2', $persisted[1]->getSeqId());
        $this->assertNotEmpty($progressEvents);
    }

    public function testBackupImportServiceThrowsWhenProfileLockIsHeld(): void
    {
        $profile = $this->createProfile(12);
        $endfield = $this->createEndfieldService();
        $lockFactory = new LockFactory(new InMemoryStore());
        $heldLock = $lockFactory->createLock(PullBackupImportService::getProfileLockKey(12));
        $this->assertTrue($heldLock->acquire(false));

        try {
            $service = new PullBackupImportService(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(GryphlinePullRepository::class),
                new PullBackupImporterManager([$this->createDummyImporter([])]),
                $lockFactory,
                new ServiceLocator([EndfieldService::GAME_BIZ => static fn () => $endfield]),
            );

            $this->expectException(PullProfileLockedException::class);
            $service->import($profile, 'endfield_test_export', '/tmp/unused.json');
        } finally {
            $heldLock->release();
        }
    }
}
