<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullStatsCalculator;
use App\PerfectworldBundle\Service\Pull\PullBackupImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Manual pull operations against an in-memory table that enforces the unique identity
 * (profile, banner, pulled_at, group_position) statement by statement, in Doctrine's flush order
 * (inserts, updates by id, deletes).
 */
#[AllowMockObjectsWithoutExpectations]
class ManualPullServiceTest extends TestCase
{
    private const string LIMITED = NevernessToEvernessService::BANNER_LIMITED;
    private const string ARC = NevernessToEvernessService::BANNER_ARC;

    private const array CATALOG = [
        'sha' => '0123456789abcdef0123456789abcdef01234567',
        'items' => [
            '1042' => ['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'a.png'],
            '1050' => ['id' => '1050', 'name' => 'Nanally', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'b.png'],
            '1030' => ['id' => '1030', 'name' => 'Adler', 'rank' => 'A', 'type' => 'character', 'icon_path' => null],
            'nameless' => ['id' => 'nameless', 'name' => '', 'rank' => 'S', 'type' => 'character', 'icon_path' => null],
            'fork_crime' => ['id' => 'Fork_Crime', 'name' => 'Crime and Punishment', 'rank' => 'S', 'type' => 'arc', 'icon_path' => 'c.png'],
            'fork_vine' => ['id' => 'fork_vine', 'name' => 'Be Happy', 'rank' => 'B', 'type' => 'arc', 'icon_path' => null],
            'solid_dice' => ['id' => 'Solid_Dice', 'name' => 'Solid Dice', 'rank' => 'S', 'type' => 'item', 'icon_path' => null],
        ],
    ];

    /** @var array<int, PerfectworldPull> Committed rows by id */
    private array $rows = [];
    /** @var array<int, string> Committed identity of each row */
    private array $slots = [];
    /** @var list<PerfectworldPull> */
    private array $pendingInserts = [];
    /** @var array<int, PerfectworldPull> */
    private array $pendingDeletes = [];
    private int $nextId = 1;
    private int $flushes = 0;

    private LockFactory $lockFactory;
    private PerfectworldGameProfile $profile;
    private NevernessToEvernessService $game;
    private NteCatalog $catalog;

    protected function setUp(): void
    {
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->profile = self::profile(3);
        $this->game = new NevernessToEvernessService(new NullLogger());
        $this->catalog = new NteCatalog(new ArrayAdapter());
        $this->catalog->save(self::CATALOG);
    }

    private static function profile(int $id): PerfectworldGameProfile
    {
        $profile = (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID)->setGameUid('10000000000' . $id);
        (new \ReflectionProperty(PerfectworldGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    private function createService(?ManualPullGuard $guard = null): ManualPullService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(true);
        $entityManager->method('persist')->willReturnCallback(function (PerfectworldPull $pull): void {
            $this->pendingInserts[] = $pull;
        });
        $entityManager->method('remove')->willReturnCallback(function (PerfectworldPull $pull): void {
            $this->pendingDeletes[(int) $pull->getId()] = $pull;
        });
        $entityManager->method('flush')->willReturnCallback($this->flush(...));

        $repository = $this->createMock(PerfectworldPullRepository::class);
        $repository->method('findBannerRows')->willReturnCallback($this->findBannerRows(...));
        $repository->method('findBy')->willReturnCallback(fn (array $criteria): array => array_values(array_filter(
            $this->rows,
            static fn (PerfectworldPull $pull): bool => $pull->getGameProfile() === $criteria['gameProfile'] && in_array($pull->getId(), $criteria['id'], true),
        )));
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?PerfectworldPull {
            $pull = $this->rows[$criteria['id']] ?? null;

            return $pull !== null && $pull->getGameProfile() === $criteria['gameProfile'] ? $pull : null;
        });

        $planner = new PullGapPlanner();

        return new ManualPullService(
            $entityManager,
            $repository,
            new PerfectworldPullStatsCalculator($planner),
            $planner,
            $guard ?? new ManualPullGuard($planner),
            $this->catalog,
            $this->lockFactory,
            new MockClock('2026-06-10 12:00:00', 'Europe/Rome'),
        );
    }

    // ---- In-memory table -------------------------------------------------------------------

    private function flush(): void
    {
        $this->flushes++;
        $occupied = array_flip($this->slots);

        foreach ($this->pendingInserts as $pull) {
            $id = $this->nextId++;
            (new \ReflectionProperty(PerfectworldPull::class, 'id'))->setValue($pull, $id);
            $this->occupy($occupied, $id, self::slotOf($pull));
            $this->rows[$id] = $pull;
        }
        $this->pendingInserts = [];

        ksort($this->rows);
        foreach ($this->rows as $id => $pull) {
            $slot = self::slotOf($pull);
            if (isset($this->pendingDeletes[$id]) || $this->slots[$id] === $slot) {
                continue;
            }
            unset($occupied[$this->slots[$id]]);
            $this->occupy($occupied, $id, $slot);
        }

        foreach (array_keys($this->pendingDeletes) as $id) {
            unset($occupied[$this->slots[$id]], $this->slots[$id], $this->rows[$id]);
        }
        $this->pendingDeletes = [];
    }

    /**
     * @param array<string, int> $occupied
     */
    private function occupy(array &$occupied, int $id, string $slot): void
    {
        if (isset($occupied[$slot])) {
            throw new \RuntimeException(sprintf('Duplicate identity %s: row #%d, held by row #%d.', $slot, $id, $occupied[$slot]));
        }
        $occupied[$slot] = $id;
        $this->slots[$id] = $slot;
    }

    private static function slotOf(PerfectworldPull $pull): string
    {
        return sprintf('%d|%s|%s|%d', $pull->getGameProfile()?->getId(), $pull->getBannerKey(), $pull->getPulledAt()?->format('Y-m-d H:i:s'), $pull->getGroupPosition());
    }

    /**
     * Same shape and order as PerfectworldPullRepository::findBannerRows().
     *
     * @return list<array<string, mixed>>
     */
    private function findBannerRows(PerfectworldGameProfile $profile, string $bannerKey): array
    {
        $rows = [];
        foreach ($this->rows as $pull) {
            if ($pull->getGameProfile() !== $profile || $pull->getBannerKey() !== $bannerKey) {
                continue;
            }
            $rows[] = [
                'id' => $pull->getId(),
                'bannerKey' => $pull->getBannerKey(),
                'poolId' => $pull->getPoolId(),
                'pulledAt' => $pull->getPulledAt(),
                'groupPosition' => $pull->getGroupPosition(),
                'kind' => $pull->getKind(),
                'rollResult' => $pull->getRollResult(),
                'rewardType' => $pull->getRewardType(),
                'rewardId' => $pull->getRewardId(),
                'name' => $pull->getName(),
                'rarity' => $pull->getRarity(),
                'quantity' => $pull->getQuantity(),
                'origin' => $pull->getOrigin(),
                'declaredPity' => $pull->getDeclaredPity(),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['pulledAt']->getTimestamp(), -$a['groupPosition']] <=> [$b['pulledAt']->getTimestamp(), -$b['groupPosition']]);

        return $rows;
    }

    /**
     * Stores an imported record (position >= 0) directly, as an import would.
     */
    private function addRecorded(string $bannerKey, string $pulledAt, ?string $rewardId = null, int $position = 0, ?PerfectworldGameProfile $profile = null): PerfectworldPull
    {
        $item = $rewardId !== null ? $this->catalog->find($rewardId) : null;
        $isBoard = NevernessToEvernessService::isBoardBanner($bannerKey);
        $pull = (new PerfectworldPull())
            ->setGameProfile($profile ?? $this->profile)
            ->setBannerKey($bannerKey)
            ->setPulledAt(WallClockDateTimeImmutableType::fromString($pulledAt))
            ->setGroupPosition($position)
            ->setKind($isBoard ? PerfectworldPull::KIND_ROLL : PerfectworldPull::KIND_DRAW)
            ->setRollResult($isBoard ? 4 : null)
            ->setRewardType($item['type'] ?? 'arc')
            ->setRewardId($item['id'] ?? 'fork_vine')
            ->setName($item['name'] ?? 'Be Happy')
            ->setRarity($item['rarity'] ?? NevernessToEvernessService::RARITY_B)
            ->setExporterVersion('0.5.0');

        $id = $this->nextId++;
        (new \ReflectionProperty(PerfectworldPull::class, 'id'))->setValue($pull, $id);
        $this->rows[$id] = $pull;
        $this->slots[$id] = self::slotOf($pull);

        return $pull;
    }

    /**
     * @return list<PerfectworldPull>
     */
    private function rowsOf(PullOrigin $origin, string $bannerKey = self::LIMITED): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (PerfectworldPull $pull): bool => $pull->getOrigin() === $origin && $pull->getBannerKey() === $bannerKey,
        ));
    }

    /**
     * @return array<int, array<string, mixed>> Computed items by id
     */
    private function computeItems(string $bannerKey = self::LIMITED): array
    {
        $bannerGroup = $this->game->getGachaConfig()->getBannerGroups('categories')[$bannerKey];
        $items = (new PerfectworldPullStatsCalculator(new PullGapPlanner()))
            ->compute($this->findBannerRows($this->profile, $bannerKey), $bannerGroup, $this->game->getBannerPullLabels($bannerKey))['items'];

        return array_column($items, null, 'id');
    }

    private static function request(string $pulledAt, int $pity, ?string $itemId, ?int $anchorId = null, string $banner = self::LIMITED): CreateManualPullRequest
    {
        return new CreateManualPullRequest(banner: $banner, name: 'Typed name', pulledAt: $pulledAt, pity: $pity, anchorId: $anchorId, itemId: $itemId);
    }

    // ---- Tests -----------------------------------------------------------------------------

    public function testPositionsAreAllocatedBelowEveryUsedPosition(): void
    {
        $at = WallClockDateTimeImmutableType::fromString('2026-06-01 10:00:00');
        $other = WallClockDateTimeImmutableType::fromString('2026-06-01 10:00:01');
        $positions = ['2026-06-01 10:00:00' => 0, '2026-06-01 10:00:01' => -3];

        $this->assertSame(-1, ManualPullService::allocatePosition($positions, $at), 'Only recorded rows: -1');
        $this->assertSame(-2, ManualPullService::allocatePosition($positions, $at));
        $this->assertSame(-4, ManualPullService::allocatePosition($positions, $other));
        $this->assertSame(-1, ManualPullService::allocatePosition($positions, WallClockDateTimeImmutableType::fromString('2026-06-01 10:00:02')), 'Unused second');
    }

    public function testManualSOnABoardIsStoredWithItsFillers(): void
    {
        $this->addRecorded(self::LIMITED, '2026-06-01 10:00:00');
        $anchor = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');

        $manual = $this->createService()->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 4, '1050', $anchor->getId()));

        $this->assertNotNull($manual->getId());
        $this->assertSame(PullOrigin::Manual, $manual->getOrigin());
        $this->assertSame(4, $manual->getDeclaredPity());
        $this->assertSame('2026-06-01 09:00:00', $manual->getPulledAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $manual->getPulledAt()?->getTimezone()->getName(), 'Wall-clock convention of the stored rows');
        $this->assertSame(-1, $manual->getGroupPosition());
        $this->assertSame(PerfectworldPull::KIND_ROLL, $manual->getKind());
        $this->assertSame('character', $manual->getRewardType());
        $this->assertSame('1050', $manual->getRewardId());
        $this->assertSame('Nanally', $manual->getName(), 'Name from the catalog, not the typed one');
        $this->assertSame(NevernessToEvernessService::RARITY_S, $manual->getRarity());
        $this->assertSame(1, $manual->getQuantity());
        $this->assertSame('manual', $manual->getExporterVersion());
        $this->assertNull($manual->getRollResult());
        $this->assertNull($manual->getPoolId());
        $this->assertNull($manual->getExportUid());
        $this->assertNull($manual->getRawPayload());

        // pity 4 = 3 unknown rolls before the manual S (start of the history: 1 s before it)
        $fillers = $this->rowsOf(PullOrigin::Filler);
        $this->assertCount(3, $fillers);
        $this->assertSame([-1, -2, -3], array_map(static fn (PerfectworldPull $pull): ?int => $pull->getGroupPosition(), $fillers));
        foreach ($fillers as $filler) {
            $this->assertSame('2026-06-01 08:59:59', $filler->getPulledAt()?->format('Y-m-d H:i:s'));
            $this->assertSame(PerfectworldPull::KIND_ROLL, $filler->getKind());
            $this->assertSame('unknown', $filler->getRewardType());
            $this->assertSame('__filler__', $filler->getRewardId());
            $this->assertSame('Unknown', $filler->getName());
            $this->assertSame(0, $filler->getRarity());
            $this->assertSame(1, $filler->getQuantity());
            $this->assertSame('manual', $filler->getExporterVersion());
            $this->assertNull($filler->getRollResult());
            $this->assertNull($filler->getPoolId());
            $this->assertNull($filler->getExportUid());
            $this->assertNull($filler->getRawPayload());
            $this->assertNull($filler->getDeclaredPity());
        }
        $this->assertSame(2, $this->flushes, 'The manual row, then the realign');

        $items = $this->computeItems();
        $this->assertSame(4, $items[$manual->getId()]['pity']);
        $this->assertSame(2, $items[$anchor->getId()]['pity']);
        $this->assertTrue($this->createService()->realign($this->profile, $this->game, self::LIMITED)->isEmpty());
    }

    public function testManualSOnTheArcBannerUsesTheCatalogArc(): void
    {
        $manual = $this->createService()->createManualS($this->profile, $this->game, self::request('2026-06-11 10:00:00', 1, 'FORK_CRIME', banner: self::ARC));

        $this->assertSame(PerfectworldPull::KIND_DRAW, $manual->getKind());
        $this->assertSame('arc', $manual->getRewardType());
        $this->assertSame('Fork_Crime', $manual->getRewardId(), 'Canonical catalog id');
        $this->assertSame('Crime and Punishment', $manual->getName());
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler, self::ARC), 'Pity 1: no filler');
    }

    public function testTheFutureHasOneDayOfTolerance(): void
    {
        // Server clock: 2026-06-10 12:00:00 local time
        $this->createService()->createManualS($this->profile, $this->game, self::request('2026-06-11 11:59:59', 1, '1050'));

        try {
            $this->createService()->createManualS($this->profile, $this->game, self::request('2026-06-11 12:00:01', 1, '1042'));
            $this->fail('More than one day ahead must be rejected.');
        } catch (ManualPullViolationException $e) {
            $this->assertSame(ManualPullViolationException::DATE_IN_FUTURE, $e->violation);
        }
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function invalidItems(): iterable
    {
        yield 'no item id' => [self::LIMITED, null];
        yield 'unknown id' => [self::LIMITED, 'free_text'];
        yield 'A character' => [self::LIMITED, '1030'];
        yield 'S item on a board' => [self::LIMITED, 'Solid_Dice'];
        yield 'S Arc on a board' => [self::LIMITED, 'fork_crime'];
        yield 'S character on the Arc banner' => [self::ARC, '1042'];
    }

    #[DataProvider('invalidItems')]
    public function testManualSNeedsAnSOfTheBannerTypeFromTheCatalog(string $banner, ?string $itemId): void
    {
        try {
            $this->createService()->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 1, $itemId, banner: $banner));
            $this->fail('Expected an invalid_item violation.');
        } catch (ManualPullViolationException $e) {
            $this->assertSame(ManualPullViolationException::INVALID_ITEM, $e->violation);
        }

        $this->assertSame([], $this->rows);
        $this->assertTrue($this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(3))->acquire(), 'Lock not left behind');
    }

    public function testUnknownBannerIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->createService()->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 1, '1050', banner: 'Lottery_Unknown'));
    }

    public function testLockContentionIsReported(): void
    {
        $recordedS = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $manual = $this->addRecorded(self::LIMITED, '2026-06-01 09:00:00', '1050', -1)->setOrigin(PullOrigin::Manual)->setDeclaredPity(1);
        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(3));
        $lock->acquire();

        $operations = [
            fn () => $this->createService()->createManualS($this->profile, $this->game, self::request('2026-06-01 08:00:00', 1, '1050')),
            fn () => $this->createService()->declarePity($this->profile, $this->game, $recordedS, 5),
            fn () => $this->createService()->deleteManual($this->profile, $this->game, $manual),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Expected a PullProfileLockedException.');
            } catch (PullProfileLockedException) {
            }
        }

        $this->assertSame(0, $this->flushes);
    }

    public function testTheGuardChecksTheCreationWithTheTolerantNow(): void
    {
        $anchor = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $guard = $this->createMock(ManualPullGuard::class);
        $guard->expects($this->once())->method('assertCanCreate')->willReturnCallback(
            function (array $entries, ?int $anchorId, \DateTimeImmutable $pulledAt, int $pity, int $sMaxPity, ?string $itemId, string $name, bool $allowDuplicate, \DateTimeImmutable $now) use ($anchor): void {
                $this->assertSame([$anchor->getId()], array_map(static fn (PullTimelineEntry $entry): int => $entry->id, $entries));
                $this->assertSame($anchor->getId(), $anchorId);
                $this->assertSame('2026-06-01 09:00:00', $pulledAt->format('Y-m-d H:i:s'));
                $this->assertSame(2, $pity);
                $this->assertSame(90, $sMaxPity);
                $this->assertSame('1050', $itemId);
                $this->assertSame('Nanally', $name);
                $this->assertFalse($allowDuplicate);
                $this->assertSame('2026-06-11 12:00:00', $now->format('Y-m-d H:i:s'), 'Local server time + 24 h');
            },
        );

        $this->createService($guard)->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 2, '1050', $anchor->getId()));
    }

    public function testTheGuardChecksTheDeclaration(): void
    {
        $s = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $guard = $this->createMock(ManualPullGuard::class);
        $guard->expects($this->once())->method('assertCanDeclare')->with($this->countOf(1), $s->getId(), 5, 90);

        $this->createService($guard)->declarePity($this->profile, $this->game, $s, 5);
    }

    public function testRecordedPityDeclarationsAreStoredAsCorrectionsOnly(): void
    {
        $this->addRecorded(self::LIMITED, '2026-06-01 10:00:00');
        $s = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $service = $this->createService();

        $service->declarePity($this->profile, $this->game, $s, 2);
        $this->assertNull($s->getDeclaredPity(), 'Equal to the recorded pity: nothing to correct');
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler));

        $service->declarePity($this->profile, $this->game, $s, 6);
        $this->assertSame(6, $s->getDeclaredPity());
        $this->assertCount(4, $this->rowsOf(PullOrigin::Filler));
        $this->assertSame(6, $this->computeItems()[$s->getId()]['pity']);

        $service->declarePity($this->profile, $this->game, $s, null);
        $this->assertNull($s->getDeclaredPity());
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler), 'Fillers removed with the correction');
    }

    public function testAManualSKeepsAPityEqualToTheRecordedOne(): void
    {
        $anchor = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $service = $this->createService();
        $manual = $service->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 3, '1050', $anchor->getId()));
        $this->assertCount(2, $this->rowsOf(PullOrigin::Filler));

        $service->declarePity($this->profile, $this->game, $manual, 1);

        $this->assertSame(1, $manual->getDeclaredPity());
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler));
    }

    public function testOnlyTheSPityCanBeDeclared(): void
    {
        $roll = $this->addRecorded(self::LIMITED, '2026-06-01 10:00:00');

        $this->expectException(BadRequestHttpException::class);
        $this->createService()->declarePity($this->profile, $this->game, $roll, 5);
    }

    public function testOnlyManualRowsCanBeDeleted(): void
    {
        $s = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $service = $this->createService();
        $service->declarePity($this->profile, $this->game, $s, 3);
        $filler = $this->rowsOf(PullOrigin::Filler)[0];

        $official = $this->addRecorded(self::LIMITED, '2026-06-01 09:00:00', '1050', -1)->setOrigin(PullOrigin::Official)->setDeclaredPity(1);

        foreach ([$s, $filler, $official] as $pull) {
            try {
                $service->deleteManual($this->profile, $this->game, $pull);
                $this->fail('Expected a BadRequestHttpException.');
            } catch (BadRequestHttpException) {
            }
        }

        $this->assertCount(4, $this->rows);
    }

    public function testDeletingAManualSRemovesItsFillers(): void
    {
        $anchor = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $service = $this->createService();
        $manual = $service->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 4, '1050', $anchor->getId()));

        $service->deleteManual($this->profile, $this->game, $manual);

        $this->assertSame([$anchor->getId()], array_keys($this->rows));
    }

    public function testPullsOfAnotherProfileAreNotFound(): void
    {
        $other = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042', profile: self::profile(4));
        $otherManual = $this->addRecorded(self::LIMITED, '2026-06-01 09:00:00', '1050', -1, self::profile(4))->setOrigin(PullOrigin::Manual)->setDeclaredPity(1);
        $service = $this->createService();

        $operations = [
            fn () => $service->declarePity($this->profile, $this->game, $other, 5),
            fn () => $service->deleteManual($this->profile, $this->game, $otherManual),
            fn () => $service->createManualS($this->profile, $this->game, self::request('2026-06-01 08:00:00', 1, '1050', $other->getId())),
        ];
        $notFound = 0;
        foreach ($operations as $operation) {
            try {
                $operation();
            } catch (NotFoundHttpException) {
                $notFound++;
            }
        }

        $this->assertSame(3, $notFound);
        $this->assertSame(0, $this->flushes);
    }

    public function testAnAnchorOfAnotherBannerIsRejectedByTheGuard(): void
    {
        $arcS = $this->addRecorded(self::ARC, '2026-06-01 10:05:00', 'fork_crime');

        try {
            $this->createService()->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 1, '1050', $arcS->getId()));
            $this->fail('Expected an invalid_anchor violation.');
        } catch (ManualPullViolationException $e) {
            $this->assertSame(ManualPullViolationException::INVALID_ANCHOR, $e->violation);
        }
    }

    public function testMovedFillersTakeFreshSlotsInOneFlush(): void
    {
        $this->addRecorded(self::LIMITED, '2026-06-01 00:00:00', '1042');
        $s = $this->addRecorded(self::LIMITED, '2026-06-01 00:00:03', '1050');
        $service = $this->createService();

        // 5 fillers between 00:00:00 and 00:00:03: seconds 1, 1, 1, 2, 2
        $service->declarePity($this->profile, $this->game, $s, 6);
        $before = $this->rowsOf(PullOrigin::Filler);
        $this->assertSame(
            ['00:00:01|-1', '00:00:01|-2', '00:00:01|-3', '00:00:02|-1', '00:00:02|-2'],
            array_map(static fn (PerfectworldPull $pull): string => $pull->getPulledAt()?->format('H:i:s') . '|' . $pull->getGroupPosition(), $before),
        );

        // An import brings the real roll of 00:00:01 (position 0 next to the fillers): the 4 remaining
        // fillers all go to 00:00:02, where (00:00:02, -1) and (-2) are still taken during the flush
        $this->addRecorded(self::LIMITED, '2026-06-01 00:00:01');
        $flushes = $this->flushes;
        $plan = $service->realign($this->profile, $this->game, self::LIMITED);

        $this->assertSame($flushes + 1, $this->flushes, 'A single flush');
        $this->assertSame([$before[0]->getId(), $before[1]->getId(), $before[2]->getId()], array_keys($plan->fillerMoves));
        $this->assertSame([$before[4]->getId()], $plan->fillerDeletes);
        $after = [];
        foreach ($this->rowsOf(PullOrigin::Filler) as $filler) {
            $after[$filler->getId()] = $filler->getPulledAt()?->format('H:i:s') . '|' . $filler->getGroupPosition();
        }
        $this->assertSame(
            [$before[0]->getId() => '00:00:02|-3', $before[1]->getId() => '00:00:02|-4', $before[2]->getId() => '00:00:02|-5', $before[3]->getId() => '00:00:02|-1'],
            $after,
            'Moved fillers keep their ids and take positions below every one used at 00:00:02',
        );
        $this->assertSame(6, $this->computeItems()[$s->getId()]['pity']);
        $this->assertTrue($service->realign($this->profile, $this->game, self::LIMITED)->isEmpty());
    }

    public function testAManualSIsMergedIntoTheRecordedSOfALaterImport(): void
    {
        $anchor = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $service = $this->createService();
        $manual = $service->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 4, '1050', $anchor->getId()));

        // An older export brings two rolls and the real S, 30 s after the manual one
        $this->addRecorded(self::LIMITED, '2026-06-01 08:00:00');
        $this->addRecorded(self::LIMITED, '2026-06-01 08:30:00');
        $recordedS = $this->addRecorded(self::LIMITED, '2026-06-01 09:00:30', '1050');
        $plan = $service->realign($this->profile, $this->game, self::LIMITED);

        $this->assertSame([$manual->getId() => $recordedS->getId()], $plan->merges);
        $this->assertArrayNotHasKey((int) $manual->getId(), $this->rows);
        $this->assertSame(4, $recordedS->getDeclaredPity());
        $fillers = $this->rowsOf(PullOrigin::Filler);
        $this->assertCount(1, $fillers, '4 declared - 3 recorded');
        $this->assertSame('2026-06-01 07:59:59', $fillers[0]->getPulledAt()?->format('Y-m-d H:i:s'));
        $this->assertSame(4, $this->computeItems()[$recordedS->getId()]['pity']);
    }

    /**
     * @return iterable<string, array{0: ?bool, 1: ?bool, 2: ?bool}>
     */
    public static function rateUpFlagProvider(): iterable
    {
        yield 'copied when the recorded S has none' => [false, null, false];
        yield 'the recorded flag is kept' => [false, true, true];
    }

    #[DataProvider('rateUpFlagProvider')]
    public function testTheMergeCopiesTheRateUpFlagOfTheManualS(?bool $manualFlag, ?bool $recordedFlag, ?bool $expected): void
    {
        $anchor = $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042');
        $service = $this->createService();
        $service->createManualS($this->profile, $this->game, self::request('2026-06-01 09:00:00', 4, '1050', $anchor->getId()))->setIsUp($manualFlag);

        $recordedS = $this->addRecorded(self::LIMITED, '2026-06-01 09:00:30', '1050')->setIsUp($recordedFlag);
        $this->assertCount(1, $service->realign($this->profile, $this->game, self::LIMITED)->merges);
        $this->assertSame($expected, $recordedS->isUp());
    }

    public function testRealignAllCoversEveryBanner(): void
    {
        $this->addRecorded(self::LIMITED, '2026-06-01 10:05:00', '1042')->setDeclaredPity(3);
        $this->addRecorded(self::ARC, '2026-06-01 10:05:00', 'fork_crime')->setDeclaredPity(2);

        $this->createService()->realignAll($this->profile, $this->game);

        $this->assertCount(2, $this->rowsOf(PullOrigin::Filler, self::LIMITED));
        $this->assertCount(1, $this->rowsOf(PullOrigin::Filler, self::ARC));
        $this->assertSame(PerfectworldPull::KIND_DRAW, $this->rowsOf(PullOrigin::Filler, self::ARC)[0]->getKind());
    }

    public function testSuggestionsAreTheSItemsOfTheBannerType(): void
    {
        $service = $this->createService();

        $this->assertSame([
            ['item_id' => '1042', 'name' => 'Blackbird', 'item_type' => 'character', 'item_type_label' => 'Character', 'rarity' => 5],
            ['item_id' => '1050', 'name' => 'Nanally', 'item_type' => 'character', 'item_type_label' => 'Character', 'rarity' => 5],
        ], $service->getSuggestions($this->game, self::LIMITED));
        $this->assertSame(
            [['item_id' => 'Fork_Crime', 'name' => 'Crime and Punishment', 'item_type' => 'arc', 'item_type_label' => 'Arc', 'rarity' => 5]],
            $service->getSuggestions($this->game, self::ARC),
        );

        $this->expectException(BadRequestHttpException::class);
        $service->getSuggestions($this->game, 'Lottery_Unknown');
    }
}
