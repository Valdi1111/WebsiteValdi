<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\Catalog\NteCatalogBackfiller;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use App\PerfectworldBundle\Service\Pull\PullBackupImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class NteCatalogBackfillerTest extends TestCase
{
    private const array CATALOG = [
        'sha' => '0123456789abcdef0123456789abcdef01234567',
        'version' => '1.5.4',
        'built_at' => '2026-11-23T06:00:00+01:00',
        'items' => [
            '1042' => ['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => null],
            'fork_twinbirds' => ['id' => 'fork_twinbirds', 'name' => 'Crime and Punishment', 'rank' => 'S', 'type' => 'arc', 'icon_path' => null],
            'fashion_glide_1075' => ['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => null],
            'mystery_item' => ['id' => 'Mystery_item', 'name' => 'Mystery Item', 'rank' => null, 'type' => 'item', 'icon_path' => null],
        ],
        'avatars' => [],
        'pools' => [],
    ];

    public function testBackfillUpdatesChangedRowsAndKeepsValuesOnCatalogMiss(): void
    {
        $blank = $this->createPull(1, 'character', '1042', '', null);
        $unknown = $this->createPull(2, 'item', 'fork_twinbirds', 'Unknown #fork_twinbirds', null);
        $upToDate = $this->createPull(3, 'cosmetic', 'Fashion_Glide_1075', 'Sheepcopter', 4);
        $miss = $this->createPull(4, 'item', 'Brand_new_item', 'Brand New Item', 5);
        $nullRank = $this->createPull(5, 'item', 'Mystery_item', 'Old Mystery Name', 4);

        $repository = $this->createMock(PerfectworldPullRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with([], ['id' => 'ASC'], 250, 0)
            ->willReturn([$blank, $unknown, $upToDate, $miss, $nullRank]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $entityManager->expects($this->once())->method('clear');

        $changed = $this->createBackfiller($this->createCatalog(self::CATALOG), $repository, $entityManager)->backfill();

        $this->assertSame(3, $changed);

        $this->assertSame(['Blackbird', 5, 'character'], $this->values($blank));
        $this->assertSame(['Crime and Punishment', 5, 'arc'], $this->values($unknown));
        $this->assertSame(['Sheepcopter', 4, 'cosmetic'], $this->values($upToDate));
        // Unknown to the catalog: stored values untouched
        $this->assertSame(['Brand New Item', 5, 'item'], $this->values($miss));
        // Catalog rank unknown: name updated, stored rarity kept
        $this->assertSame(['Mystery Item', 4, 'item'], $this->values($nullRank));
    }

    public function testBackfillPagesThroughAllRows(): void
    {
        $firstPage = [];
        for ($id = 1; $id <= 250; ++$id) {
            $firstPage[] = $this->createPull($id, 'arc', 'fork_twinbirds', 'Crime and Punishment', 5);
        }
        $lastPage = [$this->createPull(251, 'character', '1042', '', null)];

        $repository = $this->createMock(PerfectworldPullRepository::class);
        $repository->expects($this->exactly(2))
            ->method('findBy')
            ->willReturnCallback(static fn (array $criteria, array $orderBy, int $limit, int $offset): array => match ($offset) {
                0 => $firstPage,
                250 => $lastPage,
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        // Nothing changed in the first page: only the second one is flushed
        $entityManager->expects($this->once())->method('flush');
        $entityManager->expects($this->exactly(2))->method('clear');

        $changed = $this->createBackfiller($this->createCatalog(self::CATALOG), $repository, $entityManager)->backfill();

        $this->assertSame(1, $changed);
        $this->assertSame(['Blackbird', 5, 'character'], $this->values($lastPage[0]));
    }

    public function testBackfillDoesNothingWithoutCatalog(): void
    {
        $repository = $this->createMock(PerfectworldPullRepository::class);
        $repository->expects($this->never())->method('findBy');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $backfiller = $this->createBackfiller(new NteCatalog(new ArrayAdapter()), $repository, $entityManager);

        $this->assertSame(0, $backfiller->backfill());
    }

    public function testProfilesWithChangedRowsAreRealignedUnderTheirLock(): void
    {
        $changedProfile = self::profile(3);
        $untouchedProfile = self::profile(4);
        $lockedProfile = self::profile(5);
        $pulls = [
            $this->createPull(1, 'character', '1042', '', null, $changedProfile),
            $this->createPull(2, 'arc', 'fork_twinbirds', 'Unknown #fork_twinbirds', null, $changedProfile),
            $this->createPull(3, 'character', '1042', 'Blackbird', 5, $untouchedProfile),
            $this->createPull(4, 'character', '1042', '', null, $lockedProfile),
        ];

        $repository = $this->createStub(PerfectworldPullRepository::class);
        $repository->method('findBy')->willReturn($pulls);

        $profiles = [3 => $changedProfile, 4 => $untouchedProfile, 5 => $lockedProfile];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(static fn (string $class, int $id): ?PerfectworldGameProfile => $profiles[$id] ?? null);

        $lockFactory = new LockFactory(new InMemoryStore());
        $heldByAnImport = $lockFactory->createLock(PullBackupImportService::getProfileLockKey(5));
        $heldByAnImport->acquire();

        $realigned = [];
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->once())->method('realignAll')->willReturnCallback(
            function (PerfectworldGameProfile $profile, object $game) use (&$realigned, $lockFactory): void {
                $this->assertInstanceOf(NevernessToEvernessService::class, $game);
                $this->assertFalse($lockFactory->createLock(PullBackupImportService::getProfileLockKey((int) $profile->getId()))->acquire(), 'Realigned under the profile lock');
                $realigned[] = $profile->getId();
            },
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('profile #5 not realigned'));

        $changed = $this->createBackfiller($this->createCatalog(self::CATALOG), $repository, $entityManager, $manualPullService, $lockFactory, $logger)->backfill();

        $this->assertSame(3, $changed);
        $this->assertSame([3], $realigned, 'Only profiles with a changed row; the locked one is skipped');
        $this->assertTrue($lockFactory->createLock(PullBackupImportService::getProfileLockKey(3))->acquire(), 'Lock released after the realign');
        $this->assertSame(['Blackbird', 5, 'character'], $this->values($pulls[3]), 'The locked profile is still backfilled');
    }

    public function testNothingIsRealignedWhenNoRowChanges(): void
    {
        $repository = $this->createStub(PerfectworldPullRepository::class);
        $repository->method('findBy')->willReturn([$this->createPull(1, 'character', '1042', 'Blackbird', 5, self::profile(3))]);

        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->never())->method('realignAll');

        $changed = $this->createBackfiller($this->createCatalog(self::CATALOG), $repository, $this->createStub(EntityManagerInterface::class), $manualPullService)->backfill();

        $this->assertSame(0, $changed);
    }

    private function createBackfiller(
        NteCatalog $catalog,
        PerfectworldPullRepository $repository,
        EntityManagerInterface $entityManager,
        ?ManualPullService $manualPullService = null,
        ?LockFactory $lockFactory = null,
        ?LoggerInterface $logger = null,
    ): NteCatalogBackfiller {
        $game = new NevernessToEvernessService(new NullLogger());

        return new NteCatalogBackfiller(
            $catalog,
            $repository,
            $entityManager,
            $manualPullService ?? $this->createStub(ManualPullService::class),
            $lockFactory ?? new LockFactory(new InMemoryStore()),
            new ServiceLocator([(string) NevernessToEvernessService::GAME_ID => static fn () => $game]),
            $logger ?? new NullLogger(),
        );
    }

    private static function profile(int $id): PerfectworldGameProfile
    {
        $profile = (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID)->setGameUid('10000000000' . $id);
        (new \ReflectionProperty(PerfectworldGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function createCatalog(array $stored): NteCatalog
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        $catalog->save($stored);

        return $catalog;
    }

    private function createPull(int $id, string $rewardType, string $rewardId, string $name, ?int $rarity, ?PerfectworldGameProfile $profile = null): PerfectworldPull
    {
        $pull = (new PerfectworldPull())
            ->setGameProfile($profile)
            ->setBannerKey('Lottery_LimitedCharacter')
            ->setPulledAt(new \DateTimeImmutable('2026-06-03 07:20:23', new \DateTimeZone('UTC')))
            ->setGroupPosition(0)
            ->setKind(PerfectworldPull::KIND_ROLL)
            ->setRewardType($rewardType)
            ->setRewardId($rewardId)
            ->setName($name)
            ->setRarity($rarity)
            ->setExporterVersion('0.3.0');
        (new \ReflectionProperty(PerfectworldPull::class, 'id'))->setValue($pull, $id);

        return $pull;
    }

    /**
     * @return array{?string, ?int, ?string}
     */
    private function values(PerfectworldPull $pull): array
    {
        return [$pull->getName(), $pull->getRarity(), $pull->getRewardType()];
    }
}
