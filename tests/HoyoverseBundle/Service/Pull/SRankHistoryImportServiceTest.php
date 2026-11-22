<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistory;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryBanner;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRecord;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GamesBundle\Service\Pull\SRankHistoryReconciler;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Exception\RetrieveSRankHistoryException;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\Pull\HoyoversePullStatsCalculator;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\ManualPullSuggestionProvider;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\Pull\PullBackupImportService;
use App\HoyoverseBundle\Service\Pull\SRankHistoryImportService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The whole import on Zenless banners: the entity manager and the repository are mocks backed by an in-memory list of
 * pulls (with a snapshot for the transactions), so the real reconciler, planner and realign run. The history is
 * made up and returned by the game service without any HTTP request.
 */
class SRankHistoryImportServiceTest extends TestCase
{
    private const string COOKIE = 'ltoken_v2=v2_test; ltuid_v2=100000001; ltmid_v2=mid_test';

    private HoyoverseGameProfile $profile;
    private ZenlessZoneZeroService $game;
    private LockFactory $lockFactory;
    private SRankHistory $history;
    private int $historyReads = 0;

    /** @var array<int, HoyoversePull> The "database", keyed by id */
    private array $pulls = [];
    private int $nextId = 1;
    private int $flushes = 0;
    private int $transactions = 0;
    private int $commits = 0;
    private int $rollbacks = 0;
    private int $clears = 0;
    /** @var array{0: array<int, HoyoversePull>, 1: int}|null */
    private ?array $snapshot = null;
    /** @var (\Closure(HoyoversePull): void)|null */
    private ?\Closure $onPersist = null;

    protected function setUp(): void
    {
        $account = (new HoyoverseAccount())->setCookie(self::COOKIE);
        $this->profile = (new HoyoverseGameProfile())->setAccount($account)->setGameId(ZenlessZoneZeroService::GAME_ID)->setRegion('prod_gf_eu');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($this->profile, 10);
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->history = new SRankHistory([]);

        $this->game = self::createGame(function (): SRankHistory {
            ++$this->historyReads;

            return $this->history;
        });
    }

    /**
     * The real Zenless service (gacha and rarity configuration), with the history read replaced.
     *
     * @param \Closure(RuntimeAccountDataInterface): SRankHistory $read
     */
    private static function createGame(\Closure $read): ZenlessZoneZeroService
    {
        return new class(new NullLogger(), $read) extends ZenlessZoneZeroService {
            public function __construct(LoggerInterface $logger, private readonly \Closure $read)
            {
                parent::__construct($logger);
            }

            public function getSRankHistory(RuntimeAccountDataInterface $account): SRankHistory
            {
                return ($this->read)($account);
            }
        };
    }

    private function createService(): SRankHistoryImportService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $pull): void {
            \assert($pull instanceof HoyoversePull);
            if ($pull->getId() === null) {
                (new \ReflectionProperty(HoyoversePull::class, 'id'))->setValue($pull, $this->nextId++);
            }
            $this->pulls[$pull->getId()] = $pull;
            if ($this->onPersist !== null) {
                ($this->onPersist)($pull);
            }
        });
        $em->method('remove')->willReturnCallback(function (object $pull): void {
            \assert($pull instanceof HoyoversePull);
            unset($this->pulls[$pull->getId()]);
        });
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $em->method('getReference')->willReturn($this->profile);
        $em->method('beginTransaction')->willReturnCallback(function (): void {
            ++$this->transactions;
            $this->snapshot = [array_map(static fn (HoyoversePull $pull): HoyoversePull => clone $pull, $this->pulls), $this->nextId];
        });
        $em->method('commit')->willReturnCallback(function (): void {
            ++$this->commits;
            $this->snapshot = null;
        });
        $em->method('rollback')->willReturnCallback(function (): void {
            ++$this->rollbacks;
            \assert($this->snapshot !== null);
            [$this->pulls, $this->nextId] = $this->snapshot;
            $this->snapshot = null;
        });
        $em->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });

        $repository = $this->createStub(HoyoversePullRepository::class);
        $repository->method('findBannerRows')->willReturnCallback($this->findBannerRows(...));
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter($this->pulls, static fn (HoyoversePull $pull): bool => \in_array($pull->getId(), $criteria['id'], true))),
        );

        $planner = new PullGapPlanner();
        $statsCalculator = new HoyoversePullStatsCalculator($planner);
        $manualPullService = new ManualPullService(
            $em,
            $repository,
            $statsCalculator,
            $planner,
            new ManualPullGuard($planner),
            new ManualPullSuggestionProvider($this->createStub(NanokaCatalogService::class), $repository),
            new HoyoverseServerTimezoneResolver(new MockClock('2026-11-22 12:00:00 UTC')),
            $this->lockFactory,
        );

        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $cookieUtils = new HoyolabCookieUtilsService(
            new MockHttpClient(),
            new NullLogger(),
            new Serializer([new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory))]),
            $this->createStub(HoyolabUtilsService::class),
        );

        $game = $this->game;
        $genshin = new GenshinImpactService(new NullLogger());

        return new SRankHistoryImportService(
            $em,
            $repository,
            $statsCalculator,
            new SRankHistoryReconciler($planner),
            $manualPullService,
            $cookieUtils,
            $this->lockFactory,
            new ServiceLocator([
                ZenlessZoneZeroService::GAME_ID => static fn () => $game,
                GenshinImpactService::GAME_ID => static fn () => $genshin,
            ]),
        );
    }

    /**
     * Rows as the repository returns them: wall-clock digits read back as Europe/Rome, in the repository order.
     *
     * @param list<string> $gachaTypes
     *
     * @return list<array<string, mixed>>
     */
    private function findBannerRows(HoyoverseGameProfile $profile, array $gachaTypes): array
    {
        $rows = [];
        foreach ($this->pulls as $pull) {
            if ($pull->getGameProfile() !== $profile || ($gachaTypes !== [] && !\in_array($pull->getGachaType(), $gachaTypes, true))) {
                continue;
            }
            $rows[] = [
                'id' => $pull->getId(),
                'pullId' => $pull->getPullId(),
                'gachaType' => $pull->getGachaType(),
                'gachaName' => $pull->getGachaName(),
                'itemType' => $pull->getItemType(),
                'itemId' => $pull->getItemId(),
                'name' => $pull->getName(),
                'rarity' => $pull->getRarity(),
                'pulledAt' => new \DateTimeImmutable($pull->getPulledAt()->format('Y-m-d H:i:s'), new \DateTimeZone('Europe/Rome')),
                'origin' => $pull->getOrigin(),
                'declaredPity' => $pull->getDeclaredPity(),
                'isUp' => $pull->isUp(),
                'pullNumber' => $pull->getPullNumber(),
            ];
        }
        usort($rows, HoyoversePullRepository::compareRows(...));

        return $rows;
    }

    private function addPull(string $at, int $rarity, string $name = 'Some B', string $itemId = '9001', string $gachaType = '2', PullOrigin $origin = PullOrigin::Recorded, ?int $declaredPity = null): HoyoversePull
    {
        $id = $this->nextId++;
        $pull = (new HoyoversePull())
            ->setGameProfile($this->profile)
            ->setPullId(\sprintf('17000000000000%05d', $id))
            ->setGachaType($gachaType)
            ->setGachaName('Exclusive Channel')
            ->setItemType($rarity >= 4 ? 'Agents' : 'W-Engines')
            ->setItemId($itemId)
            ->setName($name)
            ->setRarity($rarity)
            ->setPulledAt(new \DateTimeImmutable($at, new \DateTimeZone('UTC')))
            ->setOrigin($origin)
            ->setDeclaredPity($declaredPity);
        (new \ReflectionProperty(HoyoversePull::class, 'id'))->setValue($pull, $id);
        $this->pulls[$id] = $pull;

        return $pull;
    }

    /**
     * Recorded B pulls one minute apart, the first at $start.
     */
    private function addBPulls(int $count, string $start, string $gachaType = '2'): void
    {
        for ($k = 0; $k < $count; ++$k) {
            $this->addPull((new \DateTimeImmutable($start))->modify(\sprintf('+%d minutes', $k))->format('Y-m-d H:i:s'), 2, gachaType: $gachaType);
        }
    }

    private static function record(int $pullNumber, int $pity, string $at, string $itemId, string $name, ?bool $isUp = true): SRankHistoryRecord
    {
        return new SRankHistoryRecord($itemId, $name, 'Agents', $pullNumber, $pity, new \DateTimeImmutable($at, new \DateTimeZone('UTC')), $isUp);
    }

    /**
     * The Exclusive Channel stored from pull 100 (a 6-month window): Gamma is pull 130, then 5 more pulls.
     *
     * @return HoyoversePull Gamma
     */
    private function seedRecentExclusiveChannel(): HoyoversePull
    {
        $this->addBPulls(30, '2025-03-01 10:00:00');
        $gamma = $this->addPull('2025-03-01 10:30:00', 4, 'Gamma', '1003');
        $this->addBPulls(5, '2025-03-02 10:00:00');

        return $gamma;
    }

    private static function exclusiveHistory(): SRankHistoryBanner
    {
        return new SRankHistoryBanner('2', [
            self::record(130, 35, '2025-03-01 10:30:00', '1003', 'Gamma', true),
            self::record(40, 40, '2024-08-01 12:00:00', '1001', 'Alpha', false),
            self::record(95, 55, '2024-11-01 12:00:00', '1002', 'Beta', true),
        ], totalPulls: 135, sCount: 3, currentPity: 5);
    }

    /**
     * @return array<string, int|null> Computed pull number of each S by name, as the API shows them
     */
    private function sPullNumbers(string $bannerKey = '2'): array
    {
        $computed = $this->computeBanner($bannerKey);

        return array_column(array_filter($computed['items'], static fn (array $item): bool => $item['rarity'] === 4), 'pull_number', 'name');
    }

    /**
     * @return array<string, mixed>
     */
    private function computeBanner(string $bannerKey): array
    {
        $group = $this->game->getGachaConfig()->getBannerGroups()[$bannerKey];

        return (new HoyoversePullStatsCalculator(new PullGapPlanner()))->compute(
            $this->findBannerRows($this->profile, $group['gacha_types']),
            $group,
            $this->game->getRarityConfig(),
        );
    }

    /**
     * @return list<HoyoversePull>
     */
    private function rowsOf(PullOrigin $origin): array
    {
        return array_values(array_filter($this->pulls, static fn (HoyoversePull $pull): bool => $pull->getOrigin() === $origin));
    }

    public function testImportCreatesTheMissingSAndConfirmsTheStoredOnes(): void
    {
        $gamma = $this->seedRecentExclusiveChannel();
        $this->history = new SRankHistory([self::exclusiveHistory(), new SRankHistoryBanner('12', [], 0, 0, 0)]);
        $progress = [];

        $summary = $this->createService()->import($this->profile, static function ($update) use (&$progress): void {
            $progress[] = $update->phase;
        });

        $this->assertSame(SRankHistoryImportService::IMPORTER_KEY, $summary->importerKey);
        $this->assertFalse($summary->dryRun);
        $this->assertSame(6, $summary->total, 'One per configured banner');
        $this->assertSame(2, $summary->imported);
        $this->assertSame(1, $summary->updated);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame([], $summary->warnings);
        $this->assertSame(['completed'], array_values(array_unique(array_column($summary->banners, 'status'))));
        $this->assertSame(2, $summary->banners['2']['imported']);
        $this->assertSame(1, $summary->banners['2']['matched'], 'Gamma');
        $this->assertSame('fetching', $progress[0]);
        $this->assertSame([1, 1, 0], [$this->transactions, $this->commits, $this->rollbacks], 'Only the Exclusive Channel is written');

        $official = $this->rowsOf(PullOrigin::Official);
        $this->assertCount(2, $official);
        [$alpha, $beta] = $official;
        $this->assertStringStartsWith(ManualPull::OFFICIAL_ID_PREFIX, $alpha->getPullId());
        $this->assertLessThanOrEqual(64, \strlen($alpha->getPullId()));
        $this->assertSame(['2', 'Exclusive Channel', 'Agents', '1001', 'Alpha', 4], [$alpha->getGachaType(), $alpha->getGachaName(), $alpha->getItemType(), $alpha->getItemId(), $alpha->getName(), $alpha->getRarity()]);
        $this->assertSame('2024-08-01 12:00:00', $alpha->getPulledAt()->format('Y-m-d H:i:s'));
        $this->assertSame([40, 40, false], [$alpha->getPullNumber(), $alpha->getDeclaredPity(), $alpha->isUp()]);
        $this->assertSame([95, 55, true], [$beta->getPullNumber(), $beta->getDeclaredPity(), $beta->isUp()]);
        $this->assertSame(['source' => 'official'], $alpha->getRawPayload());

        // The recorded S stays recorded, with the official pity (4 pulls are still missing before it) and its rate-up flag
        $this->assertSame([PullOrigin::Recorded, 35, true, null], [$gamma->getOrigin(), $gamma->getDeclaredPity(), $gamma->isUp(), $gamma->getPullNumber()]);
        $this->assertCount(39 + 54 + 4, $this->rowsOf(PullOrigin::Filler));
        $this->assertSame(['Alpha' => 40, 'Beta' => 95, 'Gamma' => 130], $this->sPullNumbers());
        $this->assertSame(135, $this->computeBanner('2')['summary']['total_pulls'], 'The total of the official history');

        // A second import changes nothing and writes nothing
        $flushes = $this->flushes;
        $count = \count($this->pulls);
        $again = $this->createService()->import($this->profile);
        $this->assertSame([0, 0, 0], [$again->imported, $again->updated, $again->skipped]);
        $this->assertSame($flushes, $this->flushes);
        $this->assertSame(1, $this->transactions, 'Not even a transaction');
        $this->assertCount($count, $this->pulls);
    }

    public function testDryRunReportsAndWritesNothing(): void
    {
        $gamma = $this->seedRecentExclusiveChannel();
        $gamma->setDeclaredPity(50);
        $this->history = new SRankHistory([self::exclusiveHistory()]);
        $before = array_map(static fn (HoyoversePull $pull): HoyoversePull => clone $pull, $this->pulls);

        $summary = $this->createService()->import($this->profile, dryRun: true);

        $this->assertTrue($summary->dryRun);
        $this->assertSame([2, 1], [$summary->imported, $summary->updated]);
        $this->assertSame(['Exclusive Channel: Declared pity of the S "Gamma" of 2025-03-01 10:30:00: 50 replaced by the official pity 35.'], $summary->warnings, 'What would be replaced');
        $this->assertSame([0, 0], [$this->flushes, $this->transactions]);
        $this->assertEquals($before, $this->pulls, 'Not a single value changed, even in memory');
    }

    public function testAConflictSkipsOnlyItsBanner(): void
    {
        $this->seedRecentExclusiveChannel();
        $this->addPull('2025-02-01 10:00:00', 4, 'Intruder', '1099');
        // Standard Channel: pulls 1-10 stored, S at 10 confirmed, then one older S of another banner rotation is missing
        $this->addBPulls(9, '2025-04-01 10:00:00', '1');
        $standard = $this->addPull('2025-04-01 10:09:00', 4, 'Standard S', '1201', '1');
        $this->history = new SRankHistory([
            self::exclusiveHistory(),
            new SRankHistoryBanner('1', [self::record(10, 10, '2025-04-01 10:09:00', '1201', 'Standard S', false)], 10, 1),
        ]);
        $count = \count($this->pulls);

        $summary = $this->createService()->import($this->profile);

        $this->assertSame('skipped', $summary->banners['2']['status']);
        $this->assertSame('The recorded S "Intruder" of 2025-02-01 10:00:00 is not in the official history.', $summary->banners['2']['error']);
        $this->assertContains('Exclusive Channel: not imported: The recorded S "Intruder" of 2025-02-01 10:00:00 is not in the official history.', $summary->warnings);
        $this->assertSame('completed', $summary->banners['1']['status']);
        $this->assertSame([1, 0, 1], [$summary->skipped, $summary->imported, $summary->updated]);
        $this->assertFalse($standard->isUp(), 'The other banner is imported');
        $this->assertCount($count, $this->pulls, 'Nothing created on the skipped banner');
        $this->assertSame([], $this->rowsOf(PullOrigin::Official));
    }

    public function testTwoSOfOneTenPullAndAStoredSOneHourOff(): void
    {
        // Bangboo Channel stored from pull 20: Delta (pull 30) is stored one hour after its official time
        $this->addBPulls(10, '2025-06-01 10:00:00', '5');
        $this->addBPulls(5, '2025-06-01 11:00:00', '5');
        $delta = $this->addPull('2025-06-01 12:00:00', 4, 'Delta', '54004', '5');
        $this->history = new SRankHistory([new SRankHistoryBanner('5', [
            self::record(10, 10, '2025-05-01 20:00:00', '54001', 'Alpha'),
            self::record(14, 4, '2025-05-01 20:00:00', '54002', 'Beta'),
            self::record(30, 16, '2025-06-01 11:00:00', '54004', 'Delta'),
        ], 30, 3)]);

        $summary = $this->createService()->import($this->profile);

        $this->assertSame([2, 1, 0], [$summary->imported, $summary->updated, $summary->skipped]);
        $this->assertSame(['Bangboo Channel: The S "Delta" is stored at 2025-06-01 12:00:00, the official history says 2025-06-01 11:00:00 (+3600 s): the stored date is kept.'], $summary->warnings);
        $this->assertSame('2025-06-01 12:00:00', $delta->getPulledAt()->format('Y-m-d H:i:s'), 'A recorded date is never corrected');
        $this->assertSame(['Alpha' => 10, 'Beta' => 14, 'Delta' => 30], $this->sPullNumbers('5'));

        // In the second of the 10-pull: Alpha, the three pulls between the two S, Beta
        $tenPull = array_values(array_filter(
            $this->computeBanner('5')['items'],
            static fn (array $item): bool => $item['pulled_at'] === '2025-05-01 20:00:00',
        ));
        $this->assertSame(['Alpha', 'Unknown', 'Unknown', 'Unknown', 'Beta'], array_column($tenPull, 'name'));
        $this->assertSame([10, 11, 12, 13, 14], array_column($tenPull, 'pull_number'));
        $this->assertSame([10, 11, 12, 13, 14], array_column($tenPull, HoyoversePullStatsCalculator::STORED_PULL_NUMBER_FIELD), 'The stored numbers order the second');
    }

    public function testAManualSIsReplacedByTheOfficialOneAndReported(): void
    {
        $this->seedRecentExclusiveChannel();
        $manual = $this->addPull('2024-11-02 08:00:00', 4, 'Beta', '1002', origin: PullOrigin::Manual, declaredPity: 60);
        $this->history = new SRankHistory([self::exclusiveHistory()]);

        $summary = $this->createService()->import($this->profile);

        $this->assertSame([1, 2], [$summary->imported, $summary->updated], 'Alpha created, Beta (manual) and Gamma updated');
        $this->assertSame(['Exclusive Channel: Manual S "Beta" (2024-11-02 08:00:00, pity 60) replaced by the official one (2024-11-01 12:00:00, pity 55).'], $summary->warnings);
        $this->assertSame(PullOrigin::Official, $manual->getOrigin());
        $this->assertSame([95, 55, '2024-11-01 12:00:00'], [$manual->getPullNumber(), $manual->getDeclaredPity(), $manual->getPulledAt()->format('Y-m-d H:i:s')]);
        $this->assertSame(['source' => 'official'], $manual->getRawPayload());
        $this->assertSame(['Alpha' => 40, 'Beta' => 95, 'Gamma' => 130], $this->sPullNumbers());
    }

    public function testABannerOutOfPlaceAfterWritingIsRolledBack(): void
    {
        $gamma = $this->seedRecentExclusiveChannel();
        $this->history = new SRankHistory([self::exclusiveHistory()]);
        // Another writer slips an S in once the official rows are written: it splits the window of Gamma, which would no
        // longer be pull 130 (an extra B would not do: the declared pity of the window would absorb it)
        $this->onPersist = function (HoyoversePull $pull): void {
            if ($pull->getOrigin() === PullOrigin::Official && $pull->getPullNumber() === 95) {
                $this->addPull('2025-02-01 10:00:00', 4, 'Surprise', '1098');
            }
        };
        $before = \count($this->pulls);

        $summary = $this->createService()->import($this->profile);

        $this->assertSame('failed', $summary->banners['2']['status']);
        $this->assertStringStartsWith('rolled back: Pull #', $summary->banners['2']['error']);
        $this->assertSame([0, 0, 1], [$summary->imported, $summary->updated, $summary->skipped]);
        $this->assertSame([1, 0, 1, 1], [$this->transactions, $this->commits, $this->rollbacks, $this->clears]);
        $this->assertCount($before, $this->pulls, 'Back to the stored rows');
        $this->assertSame([], $this->rowsOf(PullOrigin::Official));
        $this->assertSame([], $this->rowsOf(PullOrigin::Filler));
        $this->assertNull($this->pulls[$gamma->getId()]->getDeclaredPity());
        $this->assertNull($this->pulls[$gamma->getId()]->isUp());
    }

    public function testRefusesWithoutLockCookieOrSupportedGame(): void
    {
        $this->seedRecentExclusiveChannel();
        $this->history = new SRankHistory([self::exclusiveHistory()]);
        $service = $this->createService();

        $held = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(10));
        $this->assertTrue($held->acquire(false));
        try {
            $service->import($this->profile, blockingLock: false);
            $this->fail('Expected a locked profile');
        } catch (PullProfileLockedException) {
        }
        $held->release();
        $this->assertSame(0, $this->historyReads, 'Nothing is read while another operation runs');

        $this->profile->getAccount()->setCookie('ltoken_v2=v2_test; secret_value=abc');
        try {
            $service->import($this->profile);
            $this->fail('Expected an unusable cookie');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('secret_value', $e->getMessage(), 'The cookie never reaches the message');
        }

        $genshin = (new HoyoverseGameProfile())->setAccount($this->profile->getAccount())->setGameId(GenshinImpactService::GAME_ID);
        $this->expectException(\InvalidArgumentException::class);
        $service->import($genshin);
    }

    public function testAHistoryThatCannotBeReadWritesNothingAndReleasesTheLock(): void
    {
        $this->seedRecentExclusiveChannel();
        $this->game = self::createGame(static fn (): SRankHistory => throw new RetrieveSRankHistoryException('API returned error retcode [-100]: Please login'));
        $service = $this->createService();

        try {
            $service->import($this->profile);
            $this->fail('Expected the read error');
        } catch (RetrieveSRankHistoryException) {
        }

        $this->assertSame([0, 0], [$this->flushes, $this->transactions]);
        $this->assertTrue($this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(10))->acquire(false), 'The lock is released');
    }
}
