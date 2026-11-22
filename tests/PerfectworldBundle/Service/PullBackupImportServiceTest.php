<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Model\Pull\ImportedPullRecord;
use App\PerfectworldBundle\Model\Pull\PullBackupImporterInterface;
use App\PerfectworldBundle\Model\Pull\PullFileInspection;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use App\PerfectworldBundle\Service\Pull\PullBackupImporterManager;
use App\PerfectworldBundle\Service\Pull\PullBackupImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[AllowMockObjectsWithoutExpectations]
class PullBackupImportServiceTest extends TestCase
{
    private const string BANNER = NevernessToEvernessService::BANNER_LIMITED;

    private EntityManagerInterface&MockObject $entityManager;
    private PerfectworldPullRepository&MockObject $repository;
    private LockFactory $lockFactory;
    private ManualPullService&MockObject $manualPullService;
    /** @var list<PerfectworldPull> */
    private array $persisted = [];
    /** @var list<string> Banners realigned, with whether the profile lock was held at that point */
    private array $realigned = [];
    private FillerPlan $realignPlan;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('contains')->willReturn(true);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $this->repository = $this->createMock(PerfectworldPullRepository::class);
        $this->repository->method('findBannerRows')->willReturn([]);
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->manualPullService = $this->createMock(ManualPullService::class);
        $this->manualPullService->method('realign')->willReturnCallback(function (PerfectworldGameProfile $profile, object $gameService, string $bannerKey): FillerPlan {
            $probe = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(3));
            $locked = !$probe->acquire();
            $this->realigned[] = $bannerKey . ($locked ? ' (locked)' : ' (UNLOCKED)');

            return $this->realignPlan;
        });
        $this->realignPlan = new FillerPlan();
    }

    private static function profile(): PerfectworldGameProfile
    {
        $profile = (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID)->setGameUid('100000000001');
        (new \ReflectionProperty(PerfectworldGameProfile::class, 'id'))->setValue($profile, 3);

        return $profile;
    }

    private static function record(string $pulledAt, int $position, string $rewardId = 'fork_vine', string $version = '0.5.0', string $kind = PerfectworldPull::KIND_ROLL, string $banner = self::BANNER): ImportedPullRecord
    {
        return new ImportedPullRecord($banner, null, $pulledAt, $position, $kind, 3, 'arc', $rewardId, 'Be Happy', 3, 1, null, $version);
    }

    /**
     * @param array<string, list<ImportedPullRecord>|\InvalidArgumentException> $parsedByFile
     */
    private function createService(array $parsedByFile): PullBackupImportService
    {
        $importer = new class($parsedByFile) implements PullBackupImporterInterface {
            public function __construct(private readonly array $parsedByFile)
            {
            }

            public static function getKey(): string
            {
                return 'fake';
            }

            public function getLabel(): string
            {
                return 'Fake';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getAcceptedExtensions(): array
            {
                return ['.json'];
            }

            public static function getSupportedGameServices(): array
            {
                return [NevernessToEvernessService::class];
            }

            public function getMaxFiles(): int
            {
                return 3;
            }

            public function inspect(string $filePath): PullFileInspection
            {
                $parsed = $this->parsedByFile[$filePath];
                $banner = $parsed instanceof \InvalidArgumentException ? $filePath : $parsed[0]->bannerKey;

                return new PullFileInspection('100000000001', '0.5.0', [$banner => ['name' => 'Banner ' . $banner, 'count' => is_array($parsed) ? count($parsed) : 1]]);
            }

            public function parse(PerfectworldGameProfile $profile, string $filePath): array
            {
                $parsed = $this->parsedByFile[$filePath];
                if ($parsed instanceof \InvalidArgumentException) {
                    throw $parsed;
                }

                return $parsed;
            }
        };

        $game = new NevernessToEvernessService(new NullLogger());

        return new PullBackupImportService(
            $this->entityManager,
            $this->repository,
            new PullBackupImporterManager([$importer]),
            $this->lockFactory,
            $this->manualPullService,
            new ServiceLocator([NevernessToEvernessService::GAME_ID => static fn () => $game]),
        );
    }

    public function testNewRecordsAreInsertedWithTheirWallClockTime(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([]);
        $progress = [];

        $summary = $this->createService(['a.json' => [self::record('2027-03-28 02:30:00', 0), self::record('2027-03-28 02:30:00', 1)]])
            ->import(self::profile(), 'fake', ['a.json'], function (PullImportProgress $p) use (&$progress): void {
                $progress[] = $p;
            });

        $this->assertSame(2, $summary->imported);
        $this->assertSame(0, $summary->updated);
        $this->assertCount(2, $this->persisted);
        $this->assertSame('2027-03-28 02:30:00', $this->persisted[0]->getPulledAt()?->format('Y-m-d H:i:s'), 'DST gap time kept');
        $this->assertSame('completed', $summary->banners[self::BANNER]['status']);
        $this->assertSame(2, $summary->banners[self::BANNER]['imported']);
        $this->assertNotEmpty($progress);
        $this->assertSame(2, end($progress)->banners[self::BANNER]['processed']);
    }

    public function testNewerExporterVersionUpdatesAndSameVersionSkips(): void
    {
        $stored = (new PerfectworldPull())->setKind(PerfectworldPull::KIND_POINTS_GIFT);
        $this->repository->method('findIdentityMap')->willReturn([
            '2026-06-01 10:00:00|0' => ['id' => 11, 'rewardId' => 'fork_vine', 'exporterVersion' => '0.3.0'],
            '2026-06-01 10:00:00|1' => ['id' => 12, 'rewardId' => 'fork_vine', 'exporterVersion' => '0.5.0'],
        ]);
        $this->entityManager->expects($this->once())->method('find')->with(PerfectworldPull::class, 11)->willReturn($stored);

        $summary = $this->createService(['a.json' => [
            self::record('2026-06-01 10:00:00', 0, version: '0.5.0'),
            self::record('2026-06-01 10:00:00', 1, version: '0.5.0'),
            self::record('2026-06-01 10:00:01', 0, version: '0.5.0'),
        ]])->import(self::profile(), 'fake', ['a.json']);

        $this->assertSame(1, $summary->imported);
        $this->assertSame(1, $summary->updated);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(PerfectworldPull::KIND_ROLL, $stored->getKind(), 'The 0.5 label replaced the 0.3.0 one');
    }

    public function testOlderExporterVersionNeverOverwrites(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([
            '2026-06-01 10:00:00|0' => ['id' => 11, 'rewardId' => 'fork_vine', 'exporterVersion' => '0.5.0'],
        ]);
        $this->entityManager->expects($this->never())->method('find');

        $summary = $this->createService(['a.json' => [self::record('2026-06-01 10:00:00', 0, version: '0.3.0')]])
            ->import(self::profile(), 'fake', ['a.json']);

        $this->assertSame(1, $summary->skipped);
    }

    public function testIdentityConflictAbortsTheBannerBeforeAnyWrite(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([
            '2026-06-01 10:00:00|0' => ['id' => 11, 'rewardId' => 'fork_nonos', 'exporterVersion' => '0.5.0'],
        ]);

        $service = $this->createService([
            'a.json' => [self::record('2026-06-01 09:00:00', 0), self::record('2026-06-01 10:00:00', 0, 'fork_vine')],
        ]);

        try {
            $service->import(self::profile(), 'fake', ['a.json']);
            $this->fail('A single conflicting file must fail the operation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('import aborted for this banner', $e->getMessage());
        }

        $this->assertSame([], $this->persisted, 'Nothing written for the conflicting banner');
    }

    public function testOneFailedFileDoesNotStopTheOthers(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([]);

        $summary = $this->createService([
            'bad.json' => new \InvalidArgumentException('Old export could not be repaired'),
            'good.json' => [self::record('2026-06-01 10:00:00', 0)],
        ])->import(self::profile(), 'fake', ['bad.json', 'good.json']);

        $this->assertSame(1, $summary->imported);
        $this->assertSame('failed', $summary->banners['bad.json']['status']);
        $this->assertSame('completed', $summary->banners[self::BANNER]['status']);
        $this->assertStringContainsString('could not be repaired', implode(' ', $summary->warnings));
    }

    public function testGapsBetweenPointsGiftsAreReported(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([]);
        $rows = [];
        $at = static fn (int $minute): \DateTimeImmutable => new \DateTimeImmutable(sprintf('2026-06-01 10:%02d:00', $minute), new \DateTimeZone('UTC'));
        $rows[] = ['kind' => PerfectworldPull::KIND_POINTS_GIFT, 'pulledAt' => $at(0)];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = ['kind' => PerfectworldPull::KIND_ROLL, 'pulledAt' => $at($i)];
        }
        $rows[] = ['kind' => PerfectworldPull::KIND_POINTS_GIFT, 'pulledAt' => $at(8)];

        $repository = $this->createMock(PerfectworldPullRepository::class);
        $repository->method('findIdentityMap')->willReturn([]);
        $repository->method('findBannerRows')->willReturn($rows);
        $this->repository = $repository;

        $summary = $this->createService(['a.json' => [self::record('2026-06-01 11:00:00', 0)]])->import(self::profile(), 'fake', ['a.json']);

        $this->assertStringContainsString('Possible gap', implode(' ', $summary->warnings));
        $this->assertStringContainsString('7 rolls between two Points Gifts', implode(' ', $summary->warnings));
    }

    public function testEachImportedBannerIsRealignedInsideTheLock(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([]);

        $summary = $this->createService([
            'limited.json' => [self::record('2026-06-01 10:00:00', 0)],
            'bad.json' => new \InvalidArgumentException('Broken file'),
            'arc.json' => [self::record('2026-06-01 10:00:00', 0, kind: PerfectworldPull::KIND_DRAW, banner: NevernessToEvernessService::BANNER_ARC)],
        ])->import(self::profile(), 'fake', ['limited.json', 'bad.json', 'arc.json']);

        $this->assertSame('failed', $summary->banners['bad.json']['status']);
        $this->assertSame([
            NevernessToEvernessService::BANNER_LIMITED . ' (locked)',
            NevernessToEvernessService::BANNER_ARC . ' (locked)',
        ], $this->realigned, 'Only completed banners, while the import holds the profile lock');
    }

    public function testAConflictingBannerIsNotRealigned(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([
            '2026-06-01 10:00:00|0' => ['id' => 11, 'rewardId' => 'fork_nonos', 'exporterVersion' => '0.5.0'],
        ]);

        try {
            $this->createService(['a.json' => [self::record('2026-06-01 10:00:00', 0, 'fork_vine')]])->import(self::profile(), 'fake', ['a.json']);
            $this->fail('The conflicting banner must fail.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->realigned);
    }

    public function testRealignWarningsAreReported(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([]);
        $this->realignPlan = new FillerPlan(warnings: ['No room for 3 unknown pull(s) before S #7: its neighbours are less than 2 seconds apart.']);

        $summary = $this->createService(['a.json' => [self::record('2026-06-01 10:00:00', 0)]])->import(self::profile(), 'fake', ['a.json']);

        $this->assertContains('No room for 3 unknown pull(s) before S #7: its neighbours are less than 2 seconds apart.', $summary->warnings);
    }

    public function testAnOlderExportImportsAroundManualAndFillerRows(): void
    {
        // Fillers and a manual S sit at the seconds the older export now fills: negative positions
        $this->repository->method('findIdentityMap')->willReturn([
            '2026-06-01 09:59:59|-1' => ['id' => 21, 'rewardId' => ManualPullService::FILLER_REWARD_ID, 'exporterVersion' => ManualPullService::EXPORTER_VERSION],
            '2026-06-01 09:59:59|-2' => ['id' => 22, 'rewardId' => ManualPullService::FILLER_REWARD_ID, 'exporterVersion' => ManualPullService::EXPORTER_VERSION],
            '2026-06-01 09:00:00|-1' => ['id' => 23, 'rewardId' => '1042', 'exporterVersion' => ManualPullService::EXPORTER_VERSION],
            '2026-06-01 10:00:00|0' => ['id' => 11, 'rewardId' => 'fork_vine', 'exporterVersion' => '0.5.0'],
        ]);

        $summary = $this->createService(['a.json' => [
            self::record('2026-06-01 09:00:00', 0, 'fork_nonos'),
            self::record('2026-06-01 09:59:59', 1, 'fork_nonos'),
            self::record('2026-06-01 09:59:59', 0, 'fork_vine'),
            self::record('2026-06-01 10:00:00', 0, 'fork_vine'),
        ]])->import(self::profile(), 'fake', ['a.json']);

        $this->assertSame('completed', $summary->banners[self::BANNER]['status']);
        $this->assertSame(3, $summary->imported);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame([0, 1, 0], array_map(static fn (PerfectworldPull $pull): ?int => $pull->getGroupPosition(), $this->persisted));
        $this->assertSame([self::BANNER . ' (locked)'], $this->realigned);
    }

    public function testGapWarningsIgnoreFillerAndManualRows(): void
    {
        $this->repository->method('findIdentityMap')->willReturn([]);
        $at = static fn (int $minute): \DateTimeImmutable => new \DateTimeImmutable(sprintf('2026-06-01 10:%02d:00', $minute), new \DateTimeZone('UTC'));
        $rows = [['kind' => PerfectworldPull::KIND_POINTS_GIFT, 'pulledAt' => $at(0), 'origin' => PullOrigin::Recorded]];
        for ($i = 1; $i <= 4; $i++) {
            $rows[] = ['kind' => PerfectworldPull::KIND_ROLL, 'pulledAt' => $at($i), 'origin' => PullOrigin::Filler];
        }
        $rows[] = ['kind' => PerfectworldPull::KIND_ROLL, 'pulledAt' => $at(5), 'origin' => PullOrigin::Manual];
        for ($i = 6; $i <= 15; $i++) {
            $rows[] = ['kind' => PerfectworldPull::KIND_ROLL, 'pulledAt' => $at($i), 'origin' => PullOrigin::Recorded];
        }
        $rows[] = ['kind' => PerfectworldPull::KIND_POINTS_GIFT, 'pulledAt' => $at(16), 'origin' => PullOrigin::Recorded];

        $repository = $this->createMock(PerfectworldPullRepository::class);
        $repository->method('findIdentityMap')->willReturn([]);
        $repository->method('findBannerRows')->willReturn($rows);
        $this->repository = $repository;

        $summary = $this->createService(['a.json' => [self::record('2026-06-01 11:00:00', 0)]])->import(self::profile(), 'fake', ['a.json']);

        $this->assertStringNotContainsString('Possible gap', implode(' ', $summary->warnings), '10 recorded rolls between the gifts');
    }

    public function testConcurrentImportIsRejected(): void
    {
        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(3));
        $lock->acquire();

        $this->expectException(PullProfileLockedException::class);
        $this->createService(['a.json' => [self::record('2026-06-01 10:00:00', 0)]])->import(self::profile(), 'fake', ['a.json']);
    }
}
