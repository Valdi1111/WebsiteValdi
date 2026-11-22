<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\HoyoversePullStatsCalculator;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\ManualPullSuggestionProvider;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\Pull\PullBackupImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The entity manager and the repository are mocks backed by an in-memory list of pulls, so the
 * real calculator, planner and guard run the whole write + realign cycle.
 */
class ManualPullServiceTest extends TestCase
{
    private HoyoverseGameProfile $profile;
    private GenshinImpactService $game;
    private LockFactory $lockFactory;

    /** @var array<int, HoyoversePull> The "database", keyed by id */
    private array $pulls = [];
    private int $nextId = 1;
    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->profile = (new HoyoverseGameProfile())->setGameId(GenshinImpactService::GAME_ID)->setRegion('os_euro');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($this->profile, 10);
        $this->game = new GenshinImpactService(new NullLogger());
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    private function createService(): ManualPullService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $pull): void {
            \assert($pull instanceof HoyoversePull);
            if ($pull->getId() === null) {
                (new \ReflectionProperty(HoyoversePull::class, 'id'))->setValue($pull, $this->nextId++);
            }
            $this->pulls[$pull->getId()] = $pull;
        });
        $em->method('remove')->willReturnCallback(function (object $pull): void {
            \assert($pull instanceof HoyoversePull);
            unset($this->pulls[$pull->getId()]);
        });
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $em->method('getReference')->willReturn($this->profile);

        $repository = $this->createStub(HoyoversePullRepository::class);
        $repository->method('findBannerRows')->willReturnCallback($this->findBannerRows(...));
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?HoyoversePull => ($pull = $this->pulls[$criteria['id']] ?? null) !== null && $pull->getGameProfile() === $criteria['gameProfile'] ? $pull : null,
        );
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter($this->pulls, static fn (HoyoversePull $pull): bool => \in_array($pull->getId(), $criteria['id'], true))),
        );
        $repository->method('findDistinctRecordedSItems')->willReturn([]);
        $repository->method('findDistinctRecordedItemTypes')->willReturn(['Character', 'Weapon']);

        $catalog = $this->createStub(NanokaCatalogService::class);
        $catalog->method('getGiCatalog')->willReturn([
            'id_char' => ['10000052' => ['id' => '10000052', 'name' => 'Raiden Shogun', 'rarity' => 5, 'icon' => 'x', 'item_type' => 'Character']],
            'id_weapon' => [],
        ]);

        $planner = new PullGapPlanner();

        return new ManualPullService(
            $em,
            $repository,
            new HoyoversePullStatsCalculator($planner),
            $planner,
            new ManualPullGuard($planner),
            new ManualPullSuggestionProvider($catalog, $repository),
            // 12:00 UTC is 13:00 on the Europe server (UTC+1)
            new HoyoverseServerTimezoneResolver(new MockClock('2026-11-22 12:00:00 UTC')),
            $this->lockFactory,
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

    private function addPull(string $at, int $rarity, string $name = 'Cool Steel', string $gachaType = '301', PullOrigin $origin = PullOrigin::Recorded, ?int $declaredPity = null): HoyoversePull
    {
        $id = $this->nextId++;
        $pull = (new HoyoversePull())
            ->setGameProfile($this->profile)
            ->setPullId(sprintf('17000000000000%05d', $id))
            ->setGachaType($gachaType)
            ->setGachaName('Character Event Wish')
            ->setItemType($rarity >= 5 ? 'Character' : 'Weapon')
            ->setItemId('')
            ->setName($name)
            ->setRarity($rarity)
            ->setPulledAt(new \DateTimeImmutable($at, new \DateTimeZone('Europe/Rome')))
            ->setOrigin($origin)
            ->setDeclaredPity($declaredPity);
        (new \ReflectionProperty(HoyoversePull::class, 'id'))->setValue($pull, $id);
        $this->pulls[$id] = $pull;

        return $pull;
    }

    /**
     * @return list<HoyoversePull>
     */
    private function fillers(): array
    {
        return array_values(array_filter($this->pulls, static fn (HoyoversePull $pull): bool => $pull->getOrigin() === PullOrigin::Filler));
    }

    /**
     * Furina (301), five pulls on 2 January mixing 301 and 400, then Neuvillette (400): the Character Event Wish group.
     *
     * @return array{0: HoyoversePull, 1: HoyoversePull}
     */
    private function seedCharacterEventWish(): array
    {
        $furina = $this->addPull('2026-01-01 10:00:00', 5, 'Furina');
        $this->addPull('2026-01-02 10:00:00', 3, gachaType: '400');
        $this->addPull('2026-01-02 11:00:00', 3);
        $this->addPull('2026-01-02 12:00:00', 4, 'Bennett', '400');
        $this->addPull('2026-01-02 13:00:00', 3);
        $this->addPull('2026-01-02 14:00:00', 3, gachaType: '400');
        $neuvillette = $this->addPull('2026-01-03 10:00:00', 5, 'Neuvillette', '400');

        return [$furina, $neuvillette];
    }

    public function testCreateManualSWritesTheManualRowAndItsFillers(): void
    {
        [$furina, $neuvillette] = $this->seedCharacterEventWish();
        $this->addPull('2026-01-02 10:30:00', 5, 'Kirara', '302'); // another banner: untouched
        $service = $this->createService();

        $manual = $service->createManualS($this->profile, $this->game, new CreateManualPullRequest(
            banner: '301',
            name: ' Raiden Shogun ',
            pulledAt: '2026-01-02 09:59:00',
            pity: 30,
            anchorId: $neuvillette->getId(),
            itemId: '10000052',
            itemType: 'Character',
        ));

        $this->assertStringStartsWith(ManualPull::MANUAL_ID_PREFIX, $manual->getPullId());
        $this->assertLessThanOrEqual(64, \strlen($manual->getPullId()));
        $this->assertSame($this->profile, $manual->getGameProfile());
        $this->assertSame('301', $manual->getGachaType(), 'The first gacha type of the group');
        $this->assertSame('Character Event Wish', $manual->getGachaName());
        $this->assertSame('Character', $manual->getItemType());
        $this->assertSame('10000052', $manual->getItemId());
        $this->assertSame('Raiden Shogun', $manual->getName());
        $this->assertSame(5, $manual->getRarity());
        $this->assertSame(30, $manual->getDeclaredPity());
        $this->assertSame(PullOrigin::Manual, $manual->getOrigin());
        $this->assertSame(['source' => 'manual'], $manual->getRawPayload());
        $this->assertSame('2026-01-02 09:59:00', $manual->getPulledAt()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $this->flushes, 'The manual row, then the realign changes');

        $fillers = $this->fillers();
        $this->assertCount(29, $fillers, 'Declared pity 30, the manual S alone in its window');
        foreach ($fillers as $filler) {
            $this->assertStringStartsWith(ManualPull::FILLER_ID_PREFIX, $filler->getPullId());
            $this->assertLessThanOrEqual(64, \strlen($filler->getPullId()));
            $this->assertSame('301', $filler->getGachaType());
            $this->assertSame('Character Event Wish', $filler->getGachaName());
            $this->assertSame('Unknown', $filler->getItemType());
            $this->assertNull($filler->getItemId());
            $this->assertSame(ManualPull::FILLER_NAME, $filler->getName());
            $this->assertSame(0, $filler->getRarity());
            $this->assertNull($filler->getDeclaredPity());
            $this->assertNull($filler->getRawPayload());
            $this->assertGreaterThan('2026-01-01 10:00:00', $filler->getPulledAt()->format('Y-m-d H:i:s'));
            $this->assertLessThan('2026-01-02 09:59:00', $filler->getPulledAt()->format('Y-m-d H:i:s'));
        }

        // Read back through the calculator: the manual S shows its declared pity
        $computed = (new HoyoversePullStatsCalculator(new PullGapPlanner()))->compute(
            $this->findBannerRows($this->profile, ['301', '400']),
            $this->game->getGachaConfig()->getBannerGroups()['301'],
            $this->game->getRarityConfig(),
        );
        $pities = array_column($computed['items'], 'pity', 'id');
        $this->assertSame(30, $pities[$manual->getId()]);
        $this->assertSame(6, $pities[$neuvillette->getId()], 'Five pulls of 2 January and itself');
        $this->assertSame(1, $pities[$furina->getId()]);
    }

    public function testCreateManualSResolvesOrRequiresTheItemType(): void
    {
        $this->seedCharacterEventWish();
        $service = $this->createService();

        // Free text matching a suggestion: its type is used
        $manual = $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', 'raiden shogun', '2026-01-04 10:00:00', 10));
        $this->assertSame('Character', $manual->getItemType());

        try {
            $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', 'Somebody New', '2026-01-05 10:00:00', 10));
            $this->fail('Free text without a type must be rejected');
        } catch (ManualPullViolationException $e) {
            $this->assertSame(ManualPullViolationException::ITEM_TYPE_REQUIRED, $e->violation);
        }
    }

    public function testCreateManualSIsCheckedByTheGuardAgainstTheServerTime(): void
    {
        $this->addPull('2026-11-22 08:00:00', 5, 'Furina');
        $service = $this->createService();

        // 12:30 is in the past on the Europe server (13:00), though in the future in UTC (12:00)
        $manual = $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', 'Raiden Shogun', '2026-11-22 12:30:00', 5, itemType: 'Character'));
        $this->assertSame(PullOrigin::Manual, $manual->getOrigin());

        $violations = [
            'date_in_future' => new CreateManualPullRequest('301', 'Nahida', '2026-11-22 13:30:00', 5, itemType: 'Character'),
            'pity_out_of_range' => new CreateManualPullRequest('301', 'Nahida', '2026-11-22 12:40:00', 91, itemType: 'Character'),
            // Before the last S (the manual Raiden)
            'date_out_of_range' => new CreateManualPullRequest('301', 'Nahida', '2026-11-22 12:00:00', 5, itemType: 'Character'),
            // The recorded Furina is less than 24 h away
            'possible_duplicate' => new CreateManualPullRequest('301', 'Furina', '2026-11-22 12:40:00', 5, itemType: 'Character'),
        ];
        foreach ($violations as $code => $request) {
            $count = \count($this->pulls);
            try {
                $service->createManualS($this->profile, $this->game, $request);
                $this->fail("Expected $code");
            } catch (ManualPullViolationException $e) {
                $this->assertSame($code, $e->violation);
                $this->assertCount($count, $this->pulls, 'Nothing is written');
            }
        }
    }

    public function testCreateManualSRejectsAnAnchorOfAnotherProfileAndALongItemId(): void
    {
        $this->seedCharacterEventWish();
        $otherProfile = (new HoyoverseGameProfile())->setGameId(GenshinImpactService::GAME_ID);
        $foreign = $this->addPull('2026-01-03 11:00:00', 5, 'Nahida');
        $foreign->setGameProfile($otherProfile);
        $service = $this->createService();

        try {
            $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', 'Raiden Shogun', '2026-01-03 10:30:00', 5, anchorId: $foreign->getId(), itemType: 'Character'));
            $this->fail('The anchor must be a pull of the profile');
        } catch (NotFoundHttpException) {
        }

        try {
            $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', '   ', '2026-01-04 10:00:00', 5, itemType: 'Character'));
            $this->fail('A blank name must be rejected');
        } catch (BadRequestHttpException) {
        }

        $this->expectException(BadRequestHttpException::class);
        $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', 'Raiden Shogun', '2026-01-04 10:00:00', 5, itemId: str_repeat('9', 33), itemType: 'Character'));
    }

    public function testWriteOperationsFailFastWhenTheProfileIsLocked(): void
    {
        [, $neuvillette] = $this->seedCharacterEventWish();
        $manual = $this->addPull('2026-01-04 10:00:00', 5, 'Raiden Shogun', origin: PullOrigin::Manual, declaredPity: 5);
        $service = $this->createService();

        $held = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey(10));
        $this->assertTrue($held->acquire(false));

        $operations = [
            fn () => $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', 'Nahida', '2026-01-05 10:00:00', 5, itemType: 'Character')),
            fn () => $service->declarePity($this->profile, $this->game, $neuvillette, 20),
            fn () => $service->deleteManual($this->profile, $this->game, $manual),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Expected a locked profile');
            } catch (PullProfileLockedException) {
            }
        }
        $this->assertSame(0, $this->flushes);
        $this->assertNull($neuvillette->getDeclaredPity());
    }

    public function testDeclarePityStoresNullWhenItEqualsTheRecordedPity(): void
    {
        [, $neuvillette] = $this->seedCharacterEventWish();
        $service = $this->createService();

        $service->declarePity($this->profile, $this->game, $neuvillette, 20);
        $this->assertSame(20, $neuvillette->getDeclaredPity());
        $this->assertCount(14, $this->fillers());

        $service->declarePity($this->profile, $this->game, $neuvillette, 6);
        $this->assertNull($neuvillette->getDeclaredPity(), 'Six recorded pulls: nothing is missing');
        $this->assertSame([], $this->fillers(), 'The realign removed the fillers');

        $service->declarePity($this->profile, $this->game, $neuvillette, 20);
        $service->declarePity($this->profile, $this->game, $neuvillette, null);
        $this->assertNull($neuvillette->getDeclaredPity());
        $this->assertSame([], $this->fillers());

        // A manual S keeps its pity even when it equals the recorded one
        $manual = $this->addPull('2026-01-04 10:00:00', 5, 'Raiden Shogun', origin: PullOrigin::Manual, declaredPity: 5);
        $service->declarePity($this->profile, $this->game, $manual, 1);
        $this->assertSame(1, $manual->getDeclaredPity());

        try {
            $service->declarePity($this->profile, $this->game, $manual, null);
            $this->fail('A manual S always has a pity');
        } catch (ManualPullViolationException $e) {
            $this->assertSame(ManualPullViolationException::PITY_REQUIRED, $e->violation);
        }
    }

    public function testDeclarePityRejectsNonSPullsAndPullsOfAnotherProfile(): void
    {
        $this->seedCharacterEventWish();
        $bennett = array_values(array_filter($this->pulls, static fn (HoyoversePull $pull): bool => $pull->getName() === 'Bennett'))[0];
        $service = $this->createService();

        try {
            $service->declarePity($this->profile, $this->game, $bennett, 20);
            $this->fail('Only an S');
        } catch (BadRequestHttpException) {
        }

        $foreign = $this->addPull('2026-01-05 10:00:00', 5, 'Nahida');
        $foreign->setGameProfile((new HoyoverseGameProfile())->setGameId(GenshinImpactService::GAME_ID));
        $this->expectException(NotFoundHttpException::class);
        $service->declarePity($this->profile, $this->game, $foreign, 20);
    }

    public function testDeleteManualRemovesTheRowAndItsFillers(): void
    {
        [, $neuvillette] = $this->seedCharacterEventWish();
        $service = $this->createService();

        try {
            $service->deleteManual($this->profile, $this->game, $neuvillette);
            $this->fail('A recorded pull cannot be deleted');
        } catch (BadRequestHttpException) {
        }

        $manual = $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', 'Raiden Shogun', '2026-01-04 10:00:00', 40, itemType: 'Character'));
        $this->assertCount(39, $this->fillers());

        $service->deleteManual($this->profile, $this->game, $manual);
        $this->assertArrayNotHasKey((int) $manual->getId(), $this->pulls);
        $this->assertSame([], $this->fillers());
    }

    public function testRealignMergesAManualSIntoTheRecordedSThatArrivedLater(): void
    {
        $this->addPull('2026-01-01 10:00:00', 5, 'Furina');
        $manual = $this->addPull('2026-01-02 09:59:00', 5, 'Raiden Shogun', origin: PullOrigin::Manual, declaredPity: 30);
        $service = $this->createService();
        $service->realign($this->profile, $this->game, '301');
        $this->assertCount(29, $this->fillers());

        // A sync brings the real pulls: five pulls, then the real Raiden (higher id) a few minutes after the manual date
        for ($i = 0; $i < 5; ++$i) {
            $this->addPull(sprintf('2026-01-02 10:0%d:00', $i), 3, gachaType: '400');
        }
        $recorded = $this->addPull('2026-01-02 10:05:00', 5, 'Raiden Shogun', '400');

        $plan = $service->realign($this->profile, $this->game, '400');
        $this->assertSame([$manual->getId() => $recorded->getId()], $plan->merges, 'getBanner() resolves gacha type 400 to banner 301');
        $this->assertArrayNotHasKey((int) $manual->getId(), $this->pulls);
        $this->assertSame(30, $recorded->getDeclaredPity());
        $this->assertCount(24, $this->fillers(), '30 - 6 recorded pulls');
        $this->assertTrue($service->realign($this->profile, $this->game, '301')->isEmpty(), 'Idempotent');

        // realignAll() covers every configured banner, and an unknown banner is a no-op
        $service->realignAll($this->profile, $this->game);
        $this->assertCount(24, $this->fillers());
        $this->assertTrue($service->realign($this->profile, $this->game, '999')->isEmpty());
    }

    public function testRealignMergesAManualSIntoTheFirstOfTwoCopiesOfOneTenPull(): void
    {
        $this->addPull('2026-01-10 10:00:00', 3);
        $manual = $this->addPull('2026-01-04 10:00:00', 5, 'Furina', origin: PullOrigin::Manual, declaredPity: 50);
        $service = $this->createService();
        $service->realign($this->profile, $this->game, '301');
        $this->assertCount(49, $this->fillers());

        // A backup brings the older pulls, newest first like a sync: inside the 10-pull of 12:00 the row ids
        // decrease while the pull ids grow. Furina is its 3rd and 7th pull, so the first copy is the 50th pull.
        $tenPull = [];
        for ($position = 10; $position >= 1; --$position) {
            $tenPull[$position] = $this->addPull('2026-01-04 12:00:00', \in_array($position, [3, 7], true) ? 5 : 3, \in_array($position, [3, 7], true) ? 'Furina' : 'Cool Steel')
                ->setPullId(sprintf('16000000000000%05d', 47 + $position));
        }
        for ($index = 47; $index >= 1; --$index) {
            $this->addPull(sprintf('2026-01-01 %02d:%02d:00', intdiv($index, 60), $index % 60), 3)
                ->setPullId(sprintf('16000000000000%05d', $index));
        }
        [$first, $second] = [$tenPull[3], $tenPull[7]];
        $this->assertGreaterThan($second->getId(), $first->getId(), 'The first copy was inserted after the second one');

        $plan = $service->realign($this->profile, $this->game, '301');
        $this->assertSame([$manual->getId() => $first->getId()], $plan->merges, 'Equal distance: the first copy in the timeline wins, not the lowest row id');
        $this->assertSame(50, $first->getDeclaredPity());
        $this->assertNull($second->getDeclaredPity());
        $this->assertSame([], $plan->warnings);
        $this->assertSame([], $this->fillers(), '50 recorded pulls up to the first copy: nothing is missing');
        $this->assertTrue($service->realign($this->profile, $this->game, '301')->isEmpty(), 'Idempotent');
    }

    public function testRealignCopiesTheRateUpFlagOfAMergedManualS(): void
    {
        $manual = $this->addPull('2026-01-02 09:59:00', 5, 'Raiden Shogun', origin: PullOrigin::Manual, declaredPity: 1)->setIsUp(false);
        $other = $this->addPull('2026-01-05 09:59:00', 5, 'Nahida', origin: PullOrigin::Manual, declaredPity: 1)->setIsUp(true);
        $recorded = $this->addPull('2026-01-02 10:05:00', 5, 'Raiden Shogun');
        $sourced = $this->addPull('2026-01-05 10:05:00', 5, 'Nahida')->setIsUp(false);

        $plan = $this->createService()->realign($this->profile, $this->game, '301');

        $this->assertSame([$manual->getId() => $recorded->getId(), $other->getId() => $sourced->getId()], $plan->merges);
        $this->assertFalse($recorded->isUp(), 'A false flag is copied too: it is known, unlike null');
        $this->assertFalse($sourced->isUp(), 'The flag given by the recorded row\'s own source is kept');
    }

    public function testRealignWritesNumberedFillersBetweenTwoSOfOneTenPull(): void
    {
        $this->addPull('2026-01-01 10:00:00', 5, 'Furina');
        // Two official S of one 10-pull, as an S-rank history import would write them: pulls 30 and 34 of the banner
        $first = $this->addPull('2026-01-03 12:00:00', 5, 'Raiden Shogun', origin: PullOrigin::Official, declaredPity: 29)->setPullNumber(30);
        $second = $this->addPull('2026-01-03 12:00:00', 5, 'Nahida', origin: PullOrigin::Official, declaredPity: 4)->setPullNumber(34);
        $service = $this->createService();

        $plan = $service->realign($this->profile, $this->game, '301');
        $this->assertSame([], $plan->warnings);

        $numbered = array_values(array_filter($this->fillers(), static fn (HoyoversePull $pull): bool => $pull->getPullNumber() !== null));
        $this->assertSame([31, 32, 33], array_map(static fn (HoyoversePull $pull): ?int => $pull->getPullNumber(), $numbered));
        foreach ($numbered as $filler) {
            $this->assertSame('2026-01-03 12:00:00', $filler->getPulledAt()->format('Y-m-d H:i:s'));
        }
        $this->assertCount(28 + 3, $this->fillers(), '28 before the first S (after Furina), 3 between the two');

        // In the repository order the second only holds: S, three fillers, S; the computed pull numbers follow
        $computed = (new HoyoversePullStatsCalculator(new PullGapPlanner()))->compute(
            $this->findBannerRows($this->profile, ['301', '400']),
            $this->game->getGachaConfig()->getBannerGroups()['301'],
            $this->game->getRarityConfig(),
        );
        $tail = \array_slice($computed['items'], -5);
        $this->assertSame([$first->getId(), $numbered[0]->getId(), $numbered[1]->getId(), $numbered[2]->getId(), $second->getId()], array_column($tail, 'id'));
        $this->assertSame([30, 31, 32, 33, 34], array_column($tail, 'pull_number'));
        $this->assertSame([29, 4], [$tail[0]['pity'], $tail[4]['pity']]);
        $this->assertTrue($service->realign($this->profile, $this->game, '301')->isEmpty(), 'Idempotent');

        // A wrong number on a filler is corrected in place
        $numbered[1]->setPullNumber(99);
        $fix = $service->realign($this->profile, $this->game, '301');
        $this->assertSame([], $fix->fillerCreates);
        $this->assertSame([], $fix->fillerDeletes);
        $numbers = array_map(static fn (HoyoversePull $pull): ?int => $pull->getPullNumber(), $numbered);
        sort($numbers);
        $this->assertSame([31, 32, 33], $numbers);
        $this->assertTrue($service->realign($this->profile, $this->game, '301')->isEmpty());

        // They count like manual S, and the API tells them apart
        $this->assertSame(['official', 'official'], [$tail[0]['origin'], $tail[4]['origin']]);
        $this->assertSame(['Nahida', 'Raiden Shogun', 'Furina'], array_column($computed['summary']['recent_s_pulls'], 'name'));
        $this->assertSame(3, $computed['summary']['s_count']);

        // Their pity comes from the official history: the user can neither change it nor delete them, nor add an S
        // right before one of them
        $count = \count($this->pulls);
        foreach ([
            'declare' => fn () => $service->declarePity($this->profile, $this->game, $second, 6),
            'clear' => fn () => $service->declarePity($this->profile, $this->game, $first, null),
            'add' => fn () => $service->createManualS($this->profile, $this->game, new CreateManualPullRequest('301', 'Kirara', '2026-01-02 10:00:00', 5, anchorId: $first->getId(), itemType: 'Character')),
        ] as $operation => $call) {
            try {
                $call();
                $this->fail(\sprintf('"%s" on an official S must be refused', $operation));
            } catch (ManualPullViolationException $e) {
                $this->assertSame(ManualPullViolationException::OFFICIAL_PULL, $e->violation, $operation);
            }
        }
        try {
            $service->deleteManual($this->profile, $this->game, $first);
            $this->fail('An official S is not a manual row');
        } catch (BadRequestHttpException) {
        }
        $this->assertSame([29, 4], [$first->getDeclaredPity(), $second->getDeclaredPity()]);
        $this->assertCount($count, $this->pulls, 'Nothing written, nothing deleted');
    }

    public function testRealignMergesAnOfficialSIntoTheRecordedSThatArrivedLater(): void
    {
        $this->addPull('2026-01-01 10:00:00', 5, 'Furina');
        $official = $this->addPull('2026-01-02 10:05:00', 5, 'Raiden Shogun', origin: PullOrigin::Official, declaredPity: 6)->setPullNumber(7)->setIsUp(true);
        $service = $this->createService();
        $service->realign($this->profile, $this->game, '301');
        $this->assertCount(5, $this->fillers());

        // A backup brings the real pulls of that day, the real Raiden at the same second
        for ($i = 0; $i < 5; ++$i) {
            $this->addPull(sprintf('2026-01-02 10:0%d:00', $i), 3);
        }
        $recorded = $this->addPull('2026-01-02 10:05:00', 5, 'Raiden Shogun');

        $plan = $service->realign($this->profile, $this->game, '301');
        $this->assertSame([$official->getId() => $recorded->getId()], $plan->merges);
        $this->assertArrayNotHasKey((int) $official->getId(), $this->pulls);
        $this->assertSame(PullOrigin::Recorded, $recorded->getOrigin(), 'The recorded row stays recorded');
        $this->assertSame(6, $recorded->getDeclaredPity());
        $this->assertTrue($recorded->isUp());
        $this->assertSame([], $this->fillers(), 'Six recorded pulls: nothing is missing any more');
    }
}
