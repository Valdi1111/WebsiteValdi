<?php

namespace App\PerfectworldBundle\Service\Pull;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Game\HasPullTrackerInterface;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Missing pulls of NTE banners: manual S rows, declared pities and the filler rows that make the
 * declared pities add up (shared rules in GamesBundle ManualPullGuard and PullGapPlanner).
 *
 * System rows never take a real slot: manual and filler rows get a negative group position, one
 * less than the lowest position used at their second. Imported records are always >= 0, so the
 * import identity (banner, time, position) never meets them.
 */
class ManualPullService
{
    /** reward_id of filler rows: never a catalog id, so NteCatalogBackfiller never rewrites them */
    public const string FILLER_REWARD_ID = '__filler__';
    public const string FILLER_REWARD_TYPE = 'unknown';

    /** exporter_version of manual and filler rows: an import never rewrites them (positions differ anyway) */
    public const string EXPORTER_VERSION = 'manual';

    private const float LOCK_TTL_SECONDS = 60.0;

    /**
     * The game timezone of the exports is not known yet (see the bundle README): dates up to one day
     * ahead of the server clock are not "in the future".
     */
    private const string FUTURE_TOLERANCE = '+24 hours';

    private const int NAME_MAX_LENGTH = 128;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerfectworldPullRepository $pullRepository,
        private readonly PerfectworldPullStatsCalculator $statsCalculator,
        private readonly PullGapPlanner $planner,
        private readonly ManualPullGuard $guard,
        private readonly NteCatalog $catalog,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Items a manual S of the banner can be: S characters on boards, S Arcs on the Arc banner.
     *
     * @return list<array{item_id: string, name: string, item_type: string, item_type_label: string, rarity: int}>
     *
     * @throws BadRequestHttpException when the banner is not configured
     */
    public function getSuggestions(GameInterface&HasPullTrackerInterface $gameService, string $bannerKey): array
    {
        $this->getBannerGroup($gameService, $bannerKey);
        $type = self::getManualItemType($bannerKey);

        $items = [];
        foreach (array_keys($this->catalog->all()['items']) as $id) {
            $entry = $this->catalog->find((string) $id);
            if ($entry === null || $entry['rarity'] !== NevernessToEvernessService::RARITY_S || $entry['type'] !== $type) {
                continue;
            }
            $name = trim($entry['name']);
            if ($name === '') {
                continue;
            }

            $items[] = [
                'item_id' => $entry['id'],
                'name' => $name,
                'item_type' => $type,
                'item_type_label' => PerfectworldPullStatsCalculator::getItemTypeLabel($type),
                'rarity' => NevernessToEvernessService::RARITY_S,
            ];
        }

        usort($items, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['item_id']] <=> [mb_strtolower($b['name']), $b['item_id']]);

        return $items;
    }

    /**
     * Operation B: adds an S that the history does not contain, with its real pity.
     *
     * @throws PullProfileLockedException   when another pull operation holds the profile lock
     * @throws ManualPullViolationException when a shared rule is broken or the item is not an S of the banner
     * @throws BadRequestHttpException      when the banner is not configured
     * @throws NotFoundHttpException        when the anchor is not a pull of this profile
     */
    public function createManualS(PerfectworldGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, CreateManualPullRequest $request): PerfectworldPull
    {
        $bannerKey = $request->banner;
        $bannerGroup = $this->getBannerGroup($gameService, $bannerKey);
        $item = $this->resolveItem($bannerKey, $request->itemId, $request->name);

        try {
            $pulledAt = WallClockDateTimeImmutableType::fromString($request->pulledAt);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $lock = $this->acquireLock($profile);
        try {
            $rows = $this->pullRepository->findBannerRows($profile, $bannerKey);
            $entries = $this->statsCalculator->toTimelineEntries($rows, $bannerGroup);
            if ($request->anchorId !== null) {
                $this->assertAnchorOfProfile($profile, $request->anchorId, $entries);
            }

            $this->guard->assertCanCreate(
                $entries,
                $request->anchorId,
                $pulledAt,
                $request->pity,
                (int) $bannerGroup['s_max_pity'],
                NteCatalog::normalizeId($item['id']),
                $item['name'],
                $request->allowDuplicate,
                $this->getNow()->modify(self::FUTURE_TOLERANCE),
            );

            $positions = self::buildMinPositions($rows);
            $pull = $this->createSystemRow($profile, $bannerKey, $pulledAt, self::allocatePosition($positions, $pulledAt))
                ->setRewardType($item['type'])
                ->setRewardId($item['id'])
                ->setName($item['name'])
                ->setRarity(NevernessToEvernessService::RARITY_S)
                ->setOrigin(PullOrigin::Manual)
                ->setDeclaredPity($request->pity);
            $this->entityManager->persist($pull);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $bannerKey);

            return $pull;
        } finally {
            $lock->release();
        }
    }

    /**
     * Operation A: declares the real pity of an S (null removes the correction of a recorded S).
     * Declaring the recorded pity of a recorded S stores null: there is nothing to correct.
     *
     * @throws PullProfileLockedException   when another pull operation holds the profile lock
     * @throws ManualPullViolationException when a shared rule is broken
     * @throws NotFoundHttpException        when the pull is not a pull of this profile
     * @throws BadRequestHttpException      when the pull is not an S
     */
    public function declarePity(PerfectworldGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, PerfectworldPull $pull, ?int $pity): void
    {
        $this->assertPullOfProfile($profile, $pull);
        $bannerKey = (string) $pull->getBannerKey();
        $bannerGroup = $this->getBannerGroup($gameService, $bannerKey);
        $pullId = (int) $pull->getId();

        $lock = $this->acquireLock($profile);
        try {
            $rows = $this->pullRepository->findBannerRows($profile, $bannerKey);
            $row = $this->findRow($rows, $pullId) ?? throw new NotFoundHttpException('Pull not found.');
            if (PerfectworldPullStatsCalculator::getOrigin($row) === PullOrigin::Filler || !$this->statsCalculator->isS($row, $bannerGroup)) {
                throw new BadRequestHttpException('Only the pity of an S pull can be declared.');
            }

            $entries = $this->statsCalculator->toTimelineEntries($rows, $bannerGroup);
            $this->guard->assertCanDeclare($entries, $pullId, $pity, (int) $bannerGroup['s_max_pity']);

            // A manual S always keeps its pity, even when it equals the recorded one (1)
            if ($pity !== null && $pull->getOrigin() !== PullOrigin::Manual) {
                $recordedPity = $this->planner->describe($entries, $this->getNow())['windows'][$pullId]->recordedPity;
                if ($pity === $recordedPity) {
                    $pity = null;
                }
            }

            $pull->setDeclaredPity($pity);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $bannerKey);
        } finally {
            $lock->release();
        }
    }

    /**
     * Deletes a manual S (its fillers follow through the realign step).
     *
     * @throws PullProfileLockedException when another pull operation holds the profile lock
     * @throws NotFoundHttpException      when the pull is not a pull of this profile
     * @throws BadRequestHttpException    when the pull was not added by the user
     */
    public function deleteManual(PerfectworldGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, PerfectworldPull $pull): void
    {
        $this->assertPullOfProfile($profile, $pull);
        $bannerKey = (string) $pull->getBannerKey();
        $this->getBannerGroup($gameService, $bannerKey);
        if ($pull->getOrigin() !== PullOrigin::Manual) {
            throw new BadRequestHttpException('Only pulls added manually can be deleted.');
        }

        $lock = $this->acquireLock($profile);
        try {
            $this->entityManager->remove($pull);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $bannerKey);
        } finally {
            $lock->release();
        }
    }

    /**
     * Brings one banner to its expected state (manual S merged into the recorded S that arrived
     * later, filler rows matching the declared pities) and flushes. Idempotent.
     *
     * The caller must hold the profile lock (PullBackupImportService::getProfileLockKey()).
     *
     * Every filler that is created or moved takes a fresh slot: a negative position below every
     * position used at its target second BEFORE the flush (rows about to be moved or deleted
     * included), and no two changes share a slot. So no statement of the flush can hit the unique
     * identity, whatever order Doctrine runs them in (inserts, updates, then deletes), the change is
     * a single flush (one transaction) and moved fillers keep their ids.
     */
    public function realign(PerfectworldGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, string $bannerKey): FillerPlan
    {
        $bannerGroup = $gameService->getGachaConfig()->getBannerGroups('categories')[$bannerKey]
            ?? throw new \InvalidArgumentException(sprintf('Unknown banner "%s".', $bannerKey));
        $profile = $this->getProfileReference($profile);

        $rows = $this->pullRepository->findBannerRows($profile, $bannerKey);
        $plan = $this->planner->plan($this->statsCalculator->toTimelineEntries($rows, $bannerGroup));
        if ($plan->isEmpty()) {
            return $plan;
        }

        $pulls = $this->loadPulls($profile, [
            ...array_keys($plan->declaredPityUpdates),
            ...array_keys($plan->merges),
            ...array_keys($plan->fillerMoves),
            ...$plan->fillerDeletes,
        ]);
        $positions = self::buildMinPositions($rows);

        foreach ($plan->declaredPityUpdates as $id => $declaredPity) {
            ($pulls[$id] ?? null)?->setDeclaredPity($declaredPity);
        }
        foreach (array_keys($plan->merges) as $id) {
            if (isset($pulls[$id])) {
                $this->entityManager->remove($pulls[$id]);
            }
        }
        foreach ($plan->fillerMoves as $id => $pulledAt) {
            ($pulls[$id] ?? null)?->setPulledAt($pulledAt)->setGroupPosition(self::allocatePosition($positions, $pulledAt));
        }
        foreach ($plan->fillerDeletes as $id) {
            if (isset($pulls[$id])) {
                $this->entityManager->remove($pulls[$id]);
            }
        }
        foreach ($plan->fillerCreates as $slot) {
            $this->entityManager->persist($this->createFiller($profile, $bannerKey, $slot->pulledAt, self::allocatePosition($positions, $slot->pulledAt)));
        }

        $this->entityManager->flush();

        return $plan;
    }

    /**
     * realign() on every configured banner group. The caller must hold the profile lock.
     */
    public function realignAll(PerfectworldGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService): void
    {
        foreach (array_keys($gameService->getGachaConfig()->getBannerGroups('categories')) as $bannerKey) {
            $this->realign($profile, $gameService, (string) $bannerKey);
        }
    }

    /**
     * Next free negative group position at a second: one less than the lowest position used there,
     * -1 when only recorded rows (>= 0) or none use it.
     *
     * @param array<string, int> $minPositions Lowest position per "Y-m-d H:i:s" second, updated in place
     */
    public static function allocatePosition(array &$minPositions, \DateTimeImmutable $pulledAt): int
    {
        $second = $pulledAt->format(ManualPull::DATE_FORMAT);
        $position = min(0, $minPositions[$second] ?? 0) - 1;
        $minPositions[$second] = $position;

        return $position;
    }

    /**
     * Catalog type of a manual S of the banner.
     */
    public static function getManualItemType(string $bannerKey): string
    {
        return NevernessToEvernessService::isBoardBanner($bannerKey) ? NteCatalog::TYPE_CHARACTER : NteCatalog::TYPE_ARC;
    }

    /**
     * @return array{id: string, name: string, type: string}
     */
    private function resolveItem(string $bannerKey, ?string $itemId, string $requestName): array
    {
        $type = self::getManualItemType($bannerKey);
        $entry = $itemId !== null && trim($itemId) !== '' ? $this->catalog->find($itemId) : null;
        if ($entry === null || $entry['rarity'] !== NevernessToEvernessService::RARITY_S || $entry['type'] !== $type) {
            throw new ManualPullViolationException(ManualPullViolationException::INVALID_ITEM, sprintf(
                'Choose an S %s from the list.',
                $type === NteCatalog::TYPE_ARC ? 'Arc' : 'character',
            ));
        }

        $name = trim($entry['name']) !== '' ? trim($entry['name']) : trim($requestName);

        return ['id' => $entry['id'], 'name' => mb_substr($name, 0, self::NAME_MAX_LENGTH), 'type' => $type];
    }

    private function createFiller(PerfectworldGameProfile $profile, string $bannerKey, \DateTimeImmutable $pulledAt, int $groupPosition): PerfectworldPull
    {
        return $this->createSystemRow($profile, $bannerKey, $pulledAt, $groupPosition)
            ->setRewardType(self::FILLER_REWARD_TYPE)
            ->setRewardId(self::FILLER_REWARD_ID)
            ->setName(ManualPull::FILLER_NAME)
            ->setRarity(ManualPull::FILLER_RARITY)
            ->setOrigin(PullOrigin::Filler);
    }

    /**
     * Fields shared by manual and filler rows: one pull of the banner unit, no export data.
     */
    private function createSystemRow(PerfectworldGameProfile $profile, string $bannerKey, \DateTimeImmutable $pulledAt, int $groupPosition): PerfectworldPull
    {
        return (new PerfectworldPull())
            ->setGameProfile($profile)
            ->setBannerKey($bannerKey)
            ->setPoolId(null)
            ->setPulledAt($pulledAt)
            ->setGroupPosition($groupPosition)
            ->setKind(NevernessToEvernessService::isBoardBanner($bannerKey) ? PerfectworldPull::KIND_ROLL : PerfectworldPull::KIND_DRAW)
            ->setRollResult(null)
            ->setQuantity(1)
            ->setExportUid(null)
            ->setExporterVersion(self::EXPORTER_VERSION)
            ->setRawPayload(null);
    }

    /**
     * Lowest group position used at each second of the banner, rows of every origin included.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function buildMinPositions(array $rows): array
    {
        $positions = [];
        foreach ($rows as $row) {
            $second = $row['pulledAt'] instanceof \DateTimeInterface
                ? $row['pulledAt']->format(ManualPull::DATE_FORMAT)
                : (string) $row['pulledAt'];
            $positions[$second] = min($positions[$second] ?? 0, (int) $row['groupPosition']);
        }

        return $positions;
    }

    /**
     * @param list<int> $ids
     * @return array<int, PerfectworldPull>
     */
    private function loadPulls(PerfectworldGameProfile $profile, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $pulls = [];
        foreach ($this->pullRepository->findBy(['gameProfile' => $profile, 'id' => array_values(array_unique($ids))]) as $pull) {
            $pulls[(int) $pull->getId()] = $pull;
        }

        return $pulls;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRow(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The anchor must be a pull of this profile; a pull of another banner is left to the guard
     * (invalid_anchor).
     *
     * @param list<PullTimelineEntry> $entries
     */
    private function assertAnchorOfProfile(PerfectworldGameProfile $profile, int $anchorId, array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry->id === $anchorId) {
                return;
            }
        }

        if ($this->pullRepository->findOneBy(['id' => $anchorId, 'gameProfile' => $profile]) === null) {
            throw new NotFoundHttpException(sprintf('Pull #%d not found.', $anchorId));
        }
    }

    private function assertPullOfProfile(PerfectworldGameProfile $profile, PerfectworldPull $pull): void
    {
        if ($pull->getGameProfile()?->getId() !== $profile->getId()) {
            throw new NotFoundHttpException('Pull not found.');
        }
    }

    /**
     * @return array{key: string, name: string, categories: list<string>, s_max_pity: int, a_max_pity: int}
     */
    private function getBannerGroup(GameInterface&HasPullTrackerInterface $gameService, string $bannerKey): array
    {
        return $gameService->getGachaConfig()->getBannerGroups('categories')[$bannerKey]
            ?? throw new BadRequestHttpException(sprintf('Unknown banner "%s".', $bannerKey));
    }

    private function acquireLock(PerfectworldGameProfile $profile): SharedLockInterface
    {
        $profileId = (int) $profile->getId();
        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey($profileId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire(false)) {
            throw new PullProfileLockedException(sprintf('Another pull operation is running for profile #%d.', $profileId));
        }

        return $lock;
    }

    /**
     * Current server time in the wall-clock convention of the stored rows.
     */
    private function getNow(): \DateTimeImmutable
    {
        return WallClockDateTimeImmutableType::now($this->clock->now());
    }

    /**
     * Managed profile for the new rows (an import clears the EntityManager between batches).
     */
    private function getProfileReference(PerfectworldGameProfile $profile): PerfectworldGameProfile
    {
        if ($this->entityManager->contains($profile)) {
            return $profile;
        }

        /** @var PerfectworldGameProfile $reference */
        $reference = $this->entityManager->getReference(PerfectworldGameProfile::class, (int) $profile->getId());

        return $reference;
    }
}
