<?php

namespace App\Tests\GryphlineBundle\Service\Pull;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalog;
use App\GryphlineBundle\Service\EndfieldService;
use App\GryphlineBundle\Service\Pull\GryphlinePullStatsCalculator;
use App\GryphlineBundle\Service\Pull\ManualPullService;
use App\GryphlineBundle\Service\Pull\PullBackupImportService;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ManualPullService over an in-memory "table": the repository and entity manager doubles read and write
 * the same rows, so the real planner, guard and calculator run end to end.
 */
class ManualPullServiceTest extends TestCase
{
    private const string SPECIAL = 'E_CharacterGachaPoolType_Special';
    private const string WEAPON = 'E_WeaponGachaPoolType_Standard';

    /** Doctrine hydrates pulled_at (a UTC wall time) in the PHP default timezone: emulate a non-UTC one */
    private const string HYDRATION_TIMEZONE = 'Europe/Rome';

    /** @var array<int, GryphlinePull> */
    private array $table = [];
    private int $nextId = 1;
    private int $flushes = 0;

    /** @var array<string, list<array{itemId: string, name: string, rarity: int}>> Recorded items by pool category */
    private array $recordedItems = [];

    private GryphlineGameProfile $profile;
    private EndfieldService $game;
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        $this->profile = $this->createProfile(12);
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->game = new EndfieldService(
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

    private function createProfile(int $id): GryphlineGameProfile
    {
        $profile = (new GryphlineGameProfile())->setGameId(EndfieldService::GAME_ID)->setGameBiz(EndfieldService::GAME_BIZ);
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    private function store(GryphlinePull $pull): GryphlinePull
    {
        if ($pull->getId() === null) {
            (new \ReflectionProperty(GryphlinePull::class, 'id'))->setValue($pull, $this->nextId++);
        }
        $this->table[(int) $pull->getId()] = $pull;

        return $pull;
    }

    private function recorded(string $pulledAt, int $rarity = 4, string $name = 'Item', ?string $itemId = null, string $poolType = self::SPECIAL, ?GryphlineGameProfile $profile = null): GryphlinePull
    {
        return $this->store((new GryphlinePull())
            ->setGameProfile($profile ?? $this->profile)
            ->setSeqId((string) (1000 + $this->nextId))
            ->setPoolType($poolType)
            ->setPoolCategory($poolType === self::WEAPON ? 'weapon' : 'char')
            ->setPoolId('pool_1')
            ->setPoolName('Winter Hunt')
            ->setItemId($itemId ?? 'item_' . $this->nextId)
            ->setName($name)
            ->setRarity($rarity)
            ->setPulledAt(new \DateTimeImmutable($pulledAt, new \DateTimeZone(self::HYDRATION_TIMEZONE))));
    }

    /**
     * Rows as GryphlinePullRepository::findBannerRows() returns them (dates read back in the hydration timezone).
     *
     * @param list<string> $categories
     * @return list<array<string, mixed>>
     */
    private function bannerRows(array $categories): array
    {
        $rows = [];
        foreach ($this->table as $pull) {
            if ($pull->getGameProfile()?->getId() !== $this->profile->getId()) {
                continue;
            }
            if (!\in_array($pull->getPoolType(), $categories, true) && !\in_array($pull->getPoolCategory(), $categories, true)) {
                continue;
            }
            $rows[] = [
                'id' => $pull->getId(),
                'seqId' => $pull->getSeqId(),
                'poolType' => $pull->getPoolType(),
                'poolCategory' => $pull->getPoolCategory(),
                'poolId' => $pull->getPoolId(),
                'poolName' => $pull->getPoolName(),
                'itemId' => $pull->getItemId(),
                'name' => $pull->getName(),
                'rarity' => $pull->getRarity(),
                'pulledAt' => new \DateTimeImmutable($pull->getPulledAt()->format('Y-m-d H:i:s'), new \DateTimeZone(self::HYDRATION_TIMEZONE)),
                'origin' => $pull->getOrigin(),
                'declaredPity' => $pull->getDeclaredPity(),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['pulledAt'] <=> $b['pulledAt']);

        return $rows;
    }

    private function service(?ManualPullGuard $guard = null, ?EndfieldCatalog $catalog = null): ManualPullService
    {
        $repository = $this->createStub(GryphlinePullRepository::class);
        $repository->method('findBannerRows')->willReturnCallback(fn (GryphlineGameProfile $profile, array $categories): array => $this->bannerRows($categories));
        $repository->method('find')->willReturnCallback(fn (mixed $id): ?GryphlinePull => $this->table[(int) $id] ?? null);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria): array => array_values(array_intersect_key($this->table, array_flip($criteria['id']))));
        $repository->method('findDistinctRecordedItems')->willReturnCallback(fn (string $category): array => $this->recordedItems[$category] ?? []);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->store($entity);
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            unset($this->table[$entity->getId()]);
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $planner = new PullGapPlanner();

        return new ManualPullService(
            $entityManager,
            $repository,
            new GryphlinePullStatsCalculator($planner),
            $planner,
            $guard ?? new ManualPullGuard($planner),
            $this->lockFactory,
            $catalog ?? new EndfieldCatalog(new ArrayAdapter()),
            new MockClock('2026-11-22 12:00:00', 'UTC'),
            new NullLogger(),
        );
    }

    /**
     * @return list<GryphlinePull>
     */
    private function rowsOf(PullOrigin $origin): array
    {
        return array_values(array_filter($this->table, static fn (GryphlinePull $pull): bool => $pull->getOrigin() === $origin));
    }

    /**
     * Pity shown for each S, as the list endpoint computes it from the stored rows.
     *
     * @return array<string, int|null>
     */
    private function sPities(string $bannerKey = self::SPECIAL): array
    {
        $calculator = new GryphlinePullStatsCalculator(new PullGapPlanner());
        $bannerGroup = $this->game->getGachaConfig()->getBannerGroups('categories')[$bannerKey];
        $pities = [];
        foreach ($calculator->compute($this->bannerRows($bannerGroup['categories']), $bannerGroup, $this->game->getRarityConfig())['items'] as $item) {
            if ($item['rarity'] === 6) {
                $pities[$item['name']] = $item['pity'];
            }
        }

        return $pities;
    }

    /**
     * Two B, Liino (recorded pity 3), one B.
     */
    private function seedBanner(): GryphlinePull
    {
        $this->recorded('2026-06-01 10:00:00');
        $this->recorded('2026-06-01 10:01:00');
        $liino = $this->recorded('2026-06-01 10:02:00', 6, 'Liino', 'chr_liino');
        $this->recorded('2026-06-01 10:03:00');

        return $liino;
    }

    private function request(int $pity, ?int $anchorId, string $pulledAt = '2026-06-01 09:00:00', string $name = ' Typhoeus ', ?string $itemId = 'chr_typhoeus', string $banner = self::SPECIAL): CreateManualPullRequest
    {
        return new CreateManualPullRequest(banner: $banner, name: $name, pulledAt: $pulledAt, pity: $pity, anchorId: $anchorId, itemId: $itemId);
    }

    public function testCreateManualSStoresTheManualRowThenItsFillers(): void
    {
        $liino = $this->seedBanner();

        $manual = $this->service()->createManualS($this->profile, $this->game, $this->request(4, $liino->getId()));

        $this->assertSame($manual, $this->rowsOf(PullOrigin::Manual)[0]);
        $this->assertStringStartsWith('manual_', (string) $manual->getSeqId());
        $this->assertSame(self::SPECIAL, $manual->getPoolType());
        $this->assertSame('char', $manual->getPoolCategory());
        $this->assertNull($manual->getPoolId());
        $this->assertSame('Chartered Headhunting', $manual->getPoolName());
        $this->assertSame('chr_typhoeus', $manual->getItemId());
        $this->assertSame('Typhoeus', $manual->getName());
        $this->assertSame(6, $manual->getRarity());
        $this->assertSame(4, $manual->getDeclaredPity());
        $this->assertNull($manual->getGachaTs());
        $this->assertSame(['source' => 'manual'], $manual->getRawPayload());
        $this->assertSame('2026-06-01 09:00:00', $manual->getPulledAt()->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $manual->getPulledAt()->getTimezone()->getName(), 'Stored as a UTC wall time');

        $fillers = $this->rowsOf(PullOrigin::Filler);
        $this->assertCount(3, $fillers, 'pity - 1 unknown pulls before the manual S');
        foreach ($fillers as $filler) {
            $this->assertStringStartsWith('filler_', (string) $filler->getSeqId());
            $this->assertSame(self::SPECIAL, $filler->getPoolType());
            $this->assertSame('char', $filler->getPoolCategory());
            $this->assertNull($filler->getPoolId());
            $this->assertSame('Chartered Headhunting', $filler->getPoolName());
            $this->assertNull($filler->getItemId());
            $this->assertSame('Unknown', $filler->getName());
            $this->assertSame(0, $filler->getRarity());
            $this->assertNull($filler->getDeclaredPity());
            $this->assertNull($filler->getGachaTs());
            $this->assertNull($filler->getRawPayload());
            $this->assertSame($this->profile, $filler->getGameProfile());
            $this->assertSame('2026-06-01 08:59:59', $filler->getPulledAt()->format('Y-m-d H:i:s'), 'Start of the history: one second before the S');
        }
        $this->assertNotSame($fillers[0]->getSeqId(), $fillers[1]->getSeqId());
        $this->assertSame(2, $this->flushes, 'The manual row, then the realign');

        $this->assertSame(['Typhoeus' => 4, 'Liino' => 3], $this->sPities());
    }

    public function testCreateManualSInTheOpenWindow(): void
    {
        $this->seedBanner();

        // Open window: after Liino (10:02:00), before the next pull (10:03:00)
        $this->service()->createManualS($this->profile, $this->game, $this->request(2, null, '2026-06-01 10:02:30'));

        $this->assertSame(['Liino' => 3, 'Typhoeus' => 2], $this->sPities());
        $fillers = $this->rowsOf(PullOrigin::Filler);
        $this->assertCount(1, $fillers);
        $this->assertSame('2026-06-01 10:02:15', $fillers[0]->getPulledAt()->format('Y-m-d H:i:s'), 'Strictly between Liino and the manual S');
    }

    public function testCreateManualSIsValidatedByTheGuardWithTheWallClockNow(): void
    {
        $liino = $this->seedBanner();
        $guard = $this->createMock(ManualPullGuard::class);
        $guard->expects($this->once())
            ->method('assertCanCreate')
            ->with(
                $this->callback(static fn (array $entries): bool => \count($entries) === 4),
                $liino->getId(),
                $this->callback(static fn (\DateTimeImmutable $at): bool => $at->format('Y-m-d H:i:s e') === '2026-06-01 09:00:00 UTC'),
                4,
                80,
                'chr_typhoeus',
                'Typhoeus',
                false,
                $this->callback(static fn (\DateTimeImmutable $now): bool => $now->format('Y-m-d H:i:s e') === '2026-11-22 12:00:00 UTC'),
            )
            ->willThrowException(new ManualPullViolationException(ManualPullViolationException::DATE_OUT_OF_RANGE, 'Out of range.'));

        try {
            $this->service($guard)->createManualS($this->profile, $this->game, $this->request(4, $liino->getId()));
            $this->fail('The guard violation must bubble up.');
        } catch (ManualPullViolationException $e) {
            $this->assertSame(ManualPullViolationException::DATE_OUT_OF_RANGE, $e->violation);
        }
        $this->assertCount(4, $this->table, 'Nothing written');
        $this->assertSame(0, $this->flushes);
        $this->assertTrue($this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(12))->acquire(false), 'Lock released');
    }

    public function testCreateManualSRejectsAFutureDateAndUnknownBannerAndForeignAnchor(): void
    {
        $this->recorded('2026-06-01 10:00:00');
        $this->recorded('2026-06-01 10:02:00', 6, 'Liino', 'chr_liino');
        $service = $this->service();

        try {
            $service->createManualS($this->profile, $this->game, $this->request(1, null, '2026-11-22 12:00:01'));
            $this->fail('A date after now (UTC wall clock) is rejected.');
        } catch (ManualPullViolationException $e) {
            $this->assertSame(ManualPullViolationException::DATE_IN_FUTURE, $e->violation);
        }
        $service->createManualS($this->profile, $this->game, $this->request(1, null, '2026-11-22 12:00:00', 'Si', 'chr_si'));
        $this->assertSame(['Liino' => 2, 'Si' => 1], $this->sPities(), 'Now itself is accepted');

        try {
            $service->createManualS($this->profile, $this->game, $this->request(1, null, banner: 'E_Unknown'));
            $this->fail('Unknown banner');
        } catch (BadRequestHttpException) {
        }

        $foreign = $this->recorded('2026-06-01 10:02:00', 6, 'Liino', 'chr_liino', profile: $this->createProfile(99));
        $this->expectException(NotFoundHttpException::class);
        $service->createManualS($this->profile, $this->game, $this->request(2, $foreign->getId()));
    }

    public function testWriteOperationsThrowWhileTheProfileIsLocked(): void
    {
        $liino = $this->seedBanner();
        $manual = $this->store($this->recorded('2026-06-01 09:00:00', 6, 'Manual')->setOrigin(PullOrigin::Manual)->setDeclaredPity(1));
        $service = $this->service();
        $held = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(12));
        $this->assertTrue($held->acquire(false));

        $operations = [
            'create' => fn () => $service->createManualS($this->profile, $this->game, $this->request(2, $liino->getId())),
            'declare' => fn () => $service->declarePity($this->profile, $this->game, $liino, 5),
            'delete' => fn () => $service->deleteManual($this->profile, $this->game, $manual),
        ];
        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail($name . ' must not run without the lock');
            } catch (PullProfileLockedException) {
            }
        }

        $this->assertCount(5, $this->table);
        $this->assertNull($liino->getDeclaredPity());
        $this->assertSame(0, $this->flushes);
    }

    public function testDeclarePityStoresNullWhenItEqualsTheRecordedPity(): void
    {
        $liino = $this->seedBanner();
        $service = $this->service();

        $service->declarePity($this->profile, $this->game, $liino, 3);
        $this->assertNull($liino->getDeclaredPity(), 'Equal to the recorded pity: no correction');
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler));

        $service->declarePity($this->profile, $this->game, $liino, 7);
        $this->assertSame(7, $liino->getDeclaredPity());
        $this->assertCount(4, $this->rowsOf(PullOrigin::Filler));
        $this->assertSame(['Liino' => 7], $this->sPities());

        $service->declarePity($this->profile, $this->game, $liino, null);
        $this->assertNull($liino->getDeclaredPity());
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler), 'Removing the correction removes its fillers');
        $this->assertSame(['Liino' => 3], $this->sPities());
    }

    public function testDeclarePityIsValidatedByTheGuard(): void
    {
        $liino = $this->seedBanner();
        $guard = $this->createMock(ManualPullGuard::class);
        $guard->expects($this->once())
            ->method('assertCanDeclare')
            ->with($this->isArray(), $liino->getId(), 90, 80)
            ->willThrowException(new ManualPullViolationException(ManualPullViolationException::PITY_OUT_OF_RANGE, 'Too high.'));

        $this->expectException(ManualPullViolationException::class);
        $this->service($guard)->declarePity($this->profile, $this->game, $liino, 90);
    }

    public function testDeclarePityKeepsTheValueOfAManualS(): void
    {
        $liino = $this->seedBanner();
        $service = $this->service();
        $manual = $service->createManualS($this->profile, $this->game, $this->request(4, $liino->getId()));

        $service->declarePity($this->profile, $this->game, $manual, 1);

        $this->assertSame(1, $manual->getDeclaredPity(), 'A manual S always keeps its pity, even when equal to the recorded one');
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler));
    }

    public function testDeclarePityRejectsANonSPullAndAPullOfAnotherProfile(): void
    {
        $this->seedBanner();
        $service = $this->service();

        try {
            $service->declarePity($this->profile, $this->game, $this->table[1], 5);
            $this->fail('B pull');
        } catch (BadRequestHttpException) {
        }

        $foreign = $this->recorded('2026-06-01 10:02:00', 6, 'Liino', profile: $this->createProfile(99));
        $this->expectException(NotFoundHttpException::class);
        $service->declarePity($this->profile, $this->game, $foreign, 5);
    }

    public function testDeleteManualRejectsARecordedPull(): void
    {
        $liino = $this->seedBanner();

        $this->expectException(BadRequestHttpException::class);
        $this->service()->deleteManual($this->profile, $this->game, $liino);
    }

    public function testDeleteManualRejectsAnOfficialS(): void
    {
        $this->seedBanner();
        $official = $this->recorded('2026-06-01 09:00:00', 6, 'Typhoeus', 'chr_typhoeus')->setOrigin(PullOrigin::Official)->setDeclaredPity(1);
        $count = \count($this->table);

        try {
            $this->service()->deleteManual($this->profile, $this->game, $official);
            $this->fail('Only a manual row can be deleted');
        } catch (BadRequestHttpException) {
        }
        $this->assertCount($count, $this->table);
    }

    public function testDeleteManualRemovesTheFillersItNeeded(): void
    {
        $liino = $this->seedBanner();
        $service = $this->service();
        $manual = $service->createManualS($this->profile, $this->game, $this->request(5, $liino->getId()));
        $this->assertCount(4, $this->rowsOf(PullOrigin::Filler));

        $service->deleteManual($this->profile, $this->game, $manual);

        $this->assertSame([], $this->rowsOf(PullOrigin::Manual));
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler));
        $this->assertCount(4, $this->table);
    }

    public function testRealignMergesAManualSIntoTheRecordedSImportedLater(): void
    {
        $liino = $this->seedBanner();
        $service = $this->service();
        $service->createManualS($this->profile, $this->game, $this->request(4, $liino->getId()));

        // A later import brings the real Typhoeus, a few seconds away from the manual one, and the pulls before it
        $this->recorded('2026-06-01 08:00:00');
        $real = $this->recorded('2026-06-01 09:00:05', 6, 'Typhoeus', 'chr_typhoeus');

        $plan = $service->realign($this->profile, $this->game, self::SPECIAL);

        $this->assertCount(1, $plan->merges);
        $this->assertSame([], $this->rowsOf(PullOrigin::Manual));
        $this->assertSame(4, $real->getDeclaredPity(), 'The recorded S inherits the declared pity');
        $this->assertCount(2, $this->rowsOf(PullOrigin::Filler), '4 - 2 recorded pulls in its window');
        $this->assertSame(['Typhoeus' => 4, 'Liino' => 3], $this->sPities());
        $this->assertTrue($service->realign($this->profile, $this->game, self::SPECIAL)->isEmpty(), 'Idempotent');
    }

    /**
     * @return iterable<string, array{0: ?bool, 1: ?bool, 2: ?bool}>
     */
    public static function rateUpFlagProvider(): iterable
    {
        yield 'copied when the recorded S has none' => [true, null, true];
        yield 'the recorded flag is kept' => [true, false, false];
    }

    #[DataProvider('rateUpFlagProvider')]
    public function testRealignCopiesTheRateUpFlagOfTheMergedManualS(?bool $manualFlag, ?bool $recordedFlag, ?bool $expected): void
    {
        $liino = $this->seedBanner();
        $service = $this->service();
        $service->createManualS($this->profile, $this->game, $this->request(4, $liino->getId()))->setIsUp($manualFlag);

        $this->recorded('2026-06-01 08:00:00');
        $real = $this->recorded('2026-06-01 09:00:05', 6, 'Typhoeus', 'chr_typhoeus')->setIsUp($recordedFlag);

        $this->assertCount(1, $service->realign($this->profile, $this->game, self::SPECIAL)->merges);
        $this->assertSame($expected, $real->isUp());
    }

    public function testRealignAllCoversEveryConfiguredBanner(): void
    {
        $categories = [];
        $repository = $this->createMock(GryphlinePullRepository::class);
        $repository->expects($this->exactly(4))
            ->method('findBannerRows')
            ->willReturnCallback(static function (GryphlineGameProfile $profile, array $bannerCategories) use (&$categories): array {
                $categories[] = $bannerCategories;

                return [];
            });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $planner = new PullGapPlanner();

        (new ManualPullService(
            $entityManager,
            $repository,
            new GryphlinePullStatsCalculator($planner),
            $planner,
            new ManualPullGuard($planner),
            $this->lockFactory,
            new EndfieldCatalog(new ArrayAdapter()),
            new MockClock(),
            new NullLogger(),
        ))->realignAll($this->profile, $this->game);

        $this->assertSame([
            [self::SPECIAL],
            ['E_CharacterGachaPoolType_Standard'],
            [self::WEAPON],
            ['E_CharacterGachaPoolType_Beginner'],
        ], $categories);
    }

    public function testSuggestionsMergeTheCatalogWithTheRecordedItemsOfTheBannerCategory(): void
    {
        $catalog = new EndfieldCatalog(new ArrayAdapter());
        $catalog->save([
            'version' => '1.6.4@1',
            'built_at' => '2026-11-21T06:00:00+00:00',
            'items' => [
                'chr_0005_chen' => ['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 6, 'type' => 'char'],
                'chr_0002_ardelia' => ['id' => 'chr_0002_ardelia', 'name' => 'Ardelia', 'rarity' => 6, 'type' => 'char'],
                'chr_0010_perlica' => ['id' => 'chr_0010_perlica', 'name' => 'Perlica', 'rarity' => 5, 'type' => 'char'],
                'wpn_funnel_0011' => ['id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'rarity' => 6, 'type' => 'weapon'],
            ],
        ]);
        $this->recordedItems = [
            'char' => [
                ['itemId' => 'chr_0005_chen', 'name' => 'Chen (old name)', 'rarity' => 6],
                ['itemId' => 'chr_0036_jsspsi', 'name' => 'Si', 'rarity' => 6],
            ],
            'weapon' => [['itemId' => 'wpn_jet', 'name' => 'JET', 'rarity' => 6]],
        ];
        $service = $this->service(catalog: $catalog);

        $operators = $service->getSuggestions($this->game, self::SPECIAL);
        $this->assertSame([
            ['item_id' => 'chr_0002_ardelia', 'name' => 'Ardelia', 'item_type' => 'char', 'item_type_label' => 'Operator', 'rarity' => 6],
            ['item_id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'item_type' => 'char', 'item_type_label' => 'Operator', 'rarity' => 6],
            ['item_id' => 'chr_0036_jsspsi', 'name' => 'Si', 'item_type' => 'char', 'item_type_label' => 'Operator', 'rarity' => 6],
        ], $operators, 'Catalog names first, recorded items added, sorted by name, no 5★');

        $weapons = $service->getSuggestions($this->game, self::WEAPON);
        $this->assertSame(['Delivery Guaranteed', 'JET'], array_column($weapons, 'name'));
        $this->assertSame(['Weapon', 'Weapon'], array_column($weapons, 'item_type_label'));

        $withoutCatalog = $this->service()->getSuggestions($this->game, self::SPECIAL);
        $this->assertSame(['Chen (old name)', 'Si'], array_column($withoutCatalog, 'name'), 'Recorded items only until the catalog is built');

        $this->expectException(BadRequestHttpException::class);
        $service->getSuggestions($this->game, 'E_Unknown');
    }
}
