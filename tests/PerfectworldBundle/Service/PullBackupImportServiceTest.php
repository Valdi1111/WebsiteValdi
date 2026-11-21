<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Model\Pull\ImportedPullRecord;
use App\PerfectworldBundle\Model\Pull\PullBackupImporterInterface;
use App\PerfectworldBundle\Model\Pull\PullFileInspection;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\PullBackupImporterManager;
use App\PerfectworldBundle\Service\Pull\PullBackupImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[AllowMockObjectsWithoutExpectations]
class PullBackupImportServiceTest extends TestCase
{
    private const string BANNER = NevernessToEvernessService::BANNER_LIMITED;

    private EntityManagerInterface&MockObject $entityManager;
    private PerfectworldPullRepository&MockObject $repository;
    private LockFactory $lockFactory;
    /** @var list<PerfectworldPull> */
    private array $persisted = [];

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
    }

    private static function profile(): PerfectworldGameProfile
    {
        $profile = (new PerfectworldGameProfile())->setGameUid('100000000001');
        (new \ReflectionProperty(PerfectworldGameProfile::class, 'id'))->setValue($profile, 3);

        return $profile;
    }

    private static function record(string $pulledAt, int $position, string $rewardId = 'fork_vine', string $version = '0.5.0', string $kind = PerfectworldPull::KIND_ROLL): ImportedPullRecord
    {
        return new ImportedPullRecord(self::BANNER, null, $pulledAt, $position, $kind, 3, 'arc', $rewardId, 'Be Happy', 3, 1, null, $version);
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

        return new PullBackupImportService($this->entityManager, $this->repository, new PullBackupImporterManager([$importer]), $this->lockFactory);
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

    public function testConcurrentImportIsRejected(): void
    {
        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(3));
        $lock->acquire();

        $this->expectException(PullProfileLockedException::class);
        $this->createService(['a.json' => [self::record('2026-06-01 10:00:00', 0)]])->import(self::profile(), 'fake', ['a.json']);
    }
}
