<?php

namespace App\GryphlineBundle\Service\Pull;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Missing pulls of the Endfield trackers: manual S pulls, declared pities and the filler rows
 * ("unknown" pulls) that make them add up.
 *
 * The write operations take the profile pull lock (shared with the sync and the backup import) without
 * waiting, validate with the shared ManualPullGuard, write, then realign the banner. realign() and
 * realignAll() never lock: their callers hold the profile lock.
 *
 * Dates follow the pull wall clock (UTC wall time without offset, see GryphlinePullStatsCalculator).
 */
class ManualPullService
{
    private const float LOCK_TTL_SECONDS = 60.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GryphlinePullRepository $pullRepository,
        private readonly GryphlinePullStatsCalculator $statsCalculator,
        private readonly PullGapPlanner $planner,
        private readonly ManualPullGuard $guard,
        private readonly LockFactory $lockFactory,
        private readonly EndfieldCatalog $catalog,
        private readonly ClockInterface $clock,
        #[Target('gryphline')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Operation B: adds an S pull before the anchor S (or in the open window after the last S), followed by
     * pity - 1 filler rows created by the realign step.
     *
     * @throws PullProfileLockedException|ManualPullViolationException
     */
    public function createManualS(GryphlineGameProfile $profile, HasAutoPullImportInterface $gameService, CreateManualPullRequest $request): GryphlinePull
    {
        $bannerGroup = $this->getBannerGroup($gameService, $request->banner);
        try {
            $pulledAt = WallClockDateTimeImmutableType::fromString($request->pulledAt);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException(sprintf('Invalid pull date "%s": expected %s.', $request->pulledAt, ManualPull::DATE_FORMAT));
        }
        $itemId = $request->itemId !== null && trim($request->itemId) !== '' ? trim($request->itemId) : null;
        $name = trim($request->name);
        $rarityConfig = $gameService->getRarityConfig();

        $lock = $this->acquireLock($profile);
        try {
            if ($request->anchorId !== null) {
                $anchor = $this->pullRepository->find($request->anchorId);
                if ($anchor === null || !$this->belongsTo($anchor, $profile)) {
                    throw new NotFoundHttpException('Anchor pull not found.');
                }
            }

            $entries = $this->statsCalculator->toTimelineEntries(
                $this->pullRepository->findBannerRows($profile, $bannerGroup['categories']),
                $rarityConfig,
            );
            $this->guard->assertCanCreate(
                $entries,
                $request->anchorId,
                $pulledAt,
                $request->pity,
                $bannerGroup['s_max_pity'],
                $itemId,
                $name,
                $request->allowDuplicate,
                $this->now(),
            );

            $pull = $this->newSystemRow($profile, $gameService, $bannerGroup, ManualPull::MANUAL_ID_PREFIX)
                ->setItemId($itemId)
                ->setName($name)
                ->setRarity($rarityConfig->getS()->getRarity())
                ->setPulledAt($pulledAt)
                ->setDeclaredPity($request->pity)
                ->setOrigin(PullOrigin::Manual)
                ->setRawPayload(['source' => 'manual']);
            $this->entityManager->persist($pull);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $bannerGroup['key']);

            return $pull;
        } finally {
            $lock->release();
        }
    }

    /**
     * Operation A: declares the real pity of an S (null removes the correction of a recorded S).
     * A value equal to the recorded pity is stored as null on recorded rows (no correction needed).
     *
     * @throws PullProfileLockedException|ManualPullViolationException
     */
    public function declarePity(GryphlineGameProfile $profile, HasAutoPullImportInterface $gameService, GryphlinePull $pull, ?int $pity): void
    {
        $this->assertOwnPull($profile, $pull);
        $rarityConfig = $gameService->getRarityConfig();
        if ($pull->getOrigin() === PullOrigin::Filler || (int) $pull->getRarity() < $rarityConfig->getS()->getRarity()) {
            throw new BadRequestHttpException('Only the pity of an S pull can be declared.');
        }
        $bannerGroup = $this->getBannerGroupOfPull($gameService, $pull);

        $lock = $this->acquireLock($profile);
        try {
            $entries = $this->statsCalculator->toTimelineEntries(
                $this->pullRepository->findBannerRows($profile, $bannerGroup['categories']),
                $rarityConfig,
            );
            $pullId = (int) $pull->getId();
            $this->guard->assertCanDeclare($entries, $pullId, $pity, $bannerGroup['s_max_pity']);

            if ($pity !== null && $pull->getOrigin() === PullOrigin::Recorded) {
                $recordedPity = $this->planner->describe($entries, $this->now())['windows'][$pullId]->recordedPity;
                if ($pity === $recordedPity) {
                    $pity = null;
                }
            }

            $pull->setDeclaredPity($pity);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $bannerGroup['key']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Deletes a manual S; the realign step then removes or moves the fillers it needed.
     *
     * @throws PullProfileLockedException
     */
    public function deleteManual(GryphlineGameProfile $profile, HasAutoPullImportInterface $gameService, GryphlinePull $pull): void
    {
        $this->assertOwnPull($profile, $pull);
        if ($pull->getOrigin() !== PullOrigin::Manual) {
            throw new BadRequestHttpException('Only manual pulls can be deleted.');
        }
        $bannerGroup = $this->getBannerGroupOfPull($gameService, $pull);

        $lock = $this->acquireLock($profile);
        try {
            $this->entityManager->remove($pull);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $bannerGroup['key']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Brings one banner group to its expected state: manual S rows merged into the recorded S that arrived
     * later, and the filler rows the declared pities need. Flushes when something changed.
     *
     * The caller must hold the profile pull lock (PullBackupImportService::getProfileLockKey()).
     */
    public function realign(GryphlineGameProfile $profile, HasAutoPullImportInterface $gameService, string $bannerKey): FillerPlan
    {
        $bannerGroup = $gameService->getGachaConfig()->getBannerGroups('categories')[$bannerKey]
            ?? throw new \InvalidArgumentException(sprintf('Unknown banner "%s".', $bannerKey));

        $entries = $this->statsCalculator->toTimelineEntries(
            $this->pullRepository->findBannerRows($profile, $bannerGroup['categories']),
            $gameService->getRarityConfig(),
        );
        $plan = $this->planner->plan($entries);

        foreach ($plan->warnings as $warning) {
            $this->logger->warning(sprintf('Realign of banner %s for profile #%d: %s', $bannerKey, (int) $profile->getId(), $warning));
        }
        if ($plan->isEmpty()) {
            return $plan;
        }

        $touchedIds = [
            ...array_keys($plan->declaredPityUpdates),
            ...array_keys($plan->merges),
            ...array_keys($plan->fillerMoves),
            ...$plan->fillerDeletes,
        ];
        $pulls = [];
        foreach ($touchedIds === [] ? [] : $this->pullRepository->findBy(['id' => $touchedIds]) as $pull) {
            $pulls[(int) $pull->getId()] = $pull;
        }
        $get = static fn (int $id): GryphlinePull => $pulls[$id] ?? throw new \RuntimeException(sprintf('Pull #%d vanished during the realign.', $id));

        // Order of FillerPlan: declared pity updates, merged manual rows, then filler moves, deletes and creates
        foreach ($plan->declaredPityUpdates as $id => $declaredPity) {
            $get($id)->setDeclaredPity($declaredPity);
        }
        foreach (array_keys($plan->merges) as $id) {
            $this->entityManager->remove($get($id));
        }
        foreach ($plan->fillerMoves as $id => $pulledAt) {
            $get($id)->setPulledAt($pulledAt);
        }
        foreach ($plan->fillerDeletes as $id) {
            $this->entityManager->remove($get($id));
        }
        foreach ($plan->fillerCreates as $slot) {
            $this->entityManager->persist($this->newFillerRow($profile, $gameService, $bannerGroup, $slot->pulledAt));
        }

        $this->entityManager->flush();

        return $plan;
    }

    /**
     * Realigns every configured banner group. The caller must hold the profile pull lock.
     */
    public function realignAll(GryphlineGameProfile $profile, HasAutoPullImportInterface $gameService): void
    {
        foreach (array_keys($gameService->getGachaConfig()->getBannerGroups('categories')) as $bannerKey) {
            $this->realign($profile, $gameService, (string) $bannerKey);
        }
    }

    /**
     * S items a manual S of the banner can be: the 6★ of the Endfield catalog matching the banner pool category
     * (operators or weapons), merged with the distinct recorded ones (the only source until the catalog is built).
     *
     * @return list<array{item_id: string, name: string, item_type: string, item_type_label: ?string, rarity: int}>
     */
    public function getSuggestions(HasAutoPullImportInterface $gameService, string $bannerKey): array
    {
        $bannerGroup = $this->getBannerGroup($gameService, $bannerKey);
        $category = $gameService->getPoolCategoryForPoolType($bannerGroup['key']);
        $sRarity = $gameService->getRarityConfig()->getS()->getRarity();

        $byId = [];
        foreach ($this->catalog->all()['items'] as $item) {
            if (($item['rarity'] ?? null) === $sRarity && ($item['type'] ?? null) === $category && ($item['name'] ?? '') !== '') {
                $byId[(string) $item['id']] = (string) $item['name'];
            }
        }
        foreach ($this->pullRepository->findDistinctRecordedItems($category, $sRarity) as $item) {
            $byId[$item['itemId']] ??= $item['name'];
        }
        uasort($byId, static fn (string $a, string $b): int => strcasecmp($a, $b));

        $suggestions = [];
        foreach ($byId as $itemId => $name) {
            $suggestions[] = [
                'item_id' => (string) $itemId,
                'name' => $name,
                'item_type' => $category,
                'item_type_label' => GryphlinePullStatsCalculator::getItemTypeLabel($category),
                'rarity' => $sRarity,
            ];
        }

        return $suggestions;
    }

    /**
     * Banner group of a configured banner key (400 when the game has no such banner).
     *
     * @return array{key: string, name: string, categories: array<string>, s_max_pity: int, a_max_pity: int}
     */
    public function getBannerGroup(HasAutoPullImportInterface $gameService, string $bannerKey): array
    {
        return $gameService->getGachaConfig()->getBannerGroups('categories')[$bannerKey]
            ?? throw new BadRequestHttpException(sprintf('Unknown banner "%s".', $bannerKey));
    }

    /**
     * Configured banner group a pull belongs to, by its pool type (or pool category), like the overview.
     *
     * @return array{key: string, name: string, categories: array<string>, s_max_pity: int, a_max_pity: int}
     */
    private function getBannerGroupOfPull(HasAutoPullImportInterface $gameService, GryphlinePull $pull): array
    {
        $category = (string) ($pull->getPoolType() ?: $pull->getPoolCategory());
        foreach ($gameService->getGachaConfig()->getBannerGroups('categories') as $bannerGroup) {
            if (\in_array($category, $bannerGroup['categories'], true)) {
                return $bannerGroup;
            }
        }

        throw new BadRequestHttpException(sprintf('Pool type "%s" is not a configured banner.', $category));
    }

    /**
     * @param array{key: string, name: string} $bannerGroup
     */
    private function newFillerRow(GryphlineGameProfile $profile, HasAutoPullImportInterface $gameService, array $bannerGroup, \DateTimeImmutable $pulledAt): GryphlinePull
    {
        return $this->newSystemRow($profile, $gameService, $bannerGroup, ManualPull::FILLER_ID_PREFIX)
            ->setItemId(null)
            ->setName(ManualPull::FILLER_NAME)
            ->setRarity(ManualPull::FILLER_RARITY)
            ->setPulledAt($pulledAt)
            ->setOrigin(PullOrigin::Filler)
            ->setRawPayload(null);
    }

    /**
     * Pool fields shared by manual and filler rows; the seq id prefix keeps them apart from official ones.
     *
     * @param array{key: string, name: string} $bannerGroup
     */
    private function newSystemRow(GryphlineGameProfile $profile, HasAutoPullImportInterface $gameService, array $bannerGroup, string $seqIdPrefix): GryphlinePull
    {
        return (new GryphlinePull())
            ->setGameProfile($profile)
            ->setSeqId($seqIdPrefix . Uuid::v7()->toRfc4122())
            ->setPoolType($bannerGroup['key'])
            ->setPoolCategory($gameService->getPoolCategoryForPoolType($bannerGroup['key']))
            ->setPoolId(null)
            ->setPoolName($bannerGroup['name'])
            ->setGachaTs(null);
    }

    private function acquireLock(GryphlineGameProfile $profile): SharedLockInterface
    {
        $profileId = (int) $profile->getId();
        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey($profileId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire(false)) {
            throw new PullProfileLockedException(sprintf('Another pull operation is running for profile #%d. Try again in a moment.', $profileId));
        }

        return $lock;
    }

    private function assertOwnPull(GryphlineGameProfile $profile, GryphlinePull $pull): void
    {
        if (!$this->belongsTo($pull, $profile)) {
            throw new NotFoundHttpException('Pull not found.');
        }
    }

    private function belongsTo(GryphlinePull $pull, GryphlineGameProfile $profile): bool
    {
        $owner = $pull->getGameProfile();

        return $owner !== null && $owner->getId() !== null && $owner->getId() === $profile->getId();
    }

    /**
     * Now in the pull wall-clock convention (UTC). No tolerance: stored dates are exact UTC instants.
     */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(GryphlinePullStatsCalculator::WALL_CLOCK_TIMEZONE));
    }
}
