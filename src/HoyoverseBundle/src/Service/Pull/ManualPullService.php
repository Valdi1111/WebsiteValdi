<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Missing pulls of HoYoverse banners: manual S pulls, declared pities, and the realign step that
 * keeps the filler rows of a banner group in line with them (see PullGapPlanner).
 *
 * Dates follow the storage convention of HoyoverseServerTimezoneResolver: server wall-clock digits
 * tagged as UTC, like the timeline entries built by HoyoversePullStatsCalculator.
 */
class ManualPullService
{
    /** Item type of a filler row (unknown pull) */
    public const string FILLER_ITEM_TYPE = 'Unknown';

    private const float LOCK_TTL_SECONDS = 60.0;

    /** Length of the item_id column */
    private const int ITEM_ID_MAX_LENGTH = 32;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HoyoversePullRepository $pullRepository,
        private readonly HoyoversePullStatsCalculator $statsCalculator,
        private readonly PullGapPlanner $planner,
        private readonly ManualPullGuard $guard,
        private readonly ManualPullSuggestionProvider $suggestionProvider,
        private readonly HoyoverseServerTimezoneResolver $timezoneResolver,
        private readonly LockFactory $lockFactory,
        #[Target('hoyoverse')]
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Operation B: adds an S the history is missing, at the start of the anchor's window (or after the
     * last S without an anchor), then realigns the banner.
     *
     * @throws PullProfileLockedException   when another pull operation runs for the profile
     * @throws ManualPullViolationException when a shared rule is broken (422)
     * @throws NotFoundHttpException        when the anchor is not a pull of the profile
     * @throws BadRequestHttpException      for an unknown banner or an invalid item id
     */
    public function createManualS(HoyoverseGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, CreateManualPullRequest $request): HoyoversePull
    {
        $bannerGroup = $gameService->getGachaConfig()->getBannerGroups()[$request->banner]
            ?? throw new BadRequestHttpException(\sprintf('Unknown banner "%s".', $request->banner));

        $pulledAt = $this->timezoneResolver->parseServerWallClock($request->pulledAt)
            ?? throw new BadRequestHttpException(\sprintf('Invalid pull date "%s".', $request->pulledAt));

        $itemId = $request->itemId !== null && trim($request->itemId) !== '' ? trim($request->itemId) : null;
        if ($itemId !== null && mb_strlen($itemId) > self::ITEM_ID_MAX_LENGTH) {
            throw new BadRequestHttpException(\sprintf('The item id cannot be longer than %d characters.', self::ITEM_ID_MAX_LENGTH));
        }
        $name = trim($request->name);
        if ($name === '') {
            throw new BadRequestHttpException('The name of the pull cannot be empty.');
        }

        $lock = $this->acquireLock($profile);
        try {
            if ($request->anchorId !== null && $this->pullRepository->findOneBy(['id' => $request->anchorId, 'gameProfile' => $profile]) === null) {
                throw new NotFoundHttpException('Anchor pull not found.');
            }

            $itemType = $this->resolveItemType($gameService, $request->itemType, $itemId, $name);
            $rarityConfig = $gameService->getRarityConfig();
            $this->guard->assertCanCreate(
                $this->statsCalculator->toTimelineEntries($this->pullRepository->findBannerRows($profile, $bannerGroup['gacha_types']), $rarityConfig),
                $request->anchorId,
                $pulledAt,
                $request->pity,
                $bannerGroup['s_max_pity'],
                $itemId,
                $name,
                $request->allowDuplicate,
                $this->timezoneResolver->getServerWallClockNow($profile),
            );

            $pull = (new HoyoversePull())
                ->setGameProfile($profile)
                ->setPullId(ManualPull::MANUAL_ID_PREFIX.Uuid::v7()->toRfc4122())
                ->setGachaType((string) $bannerGroup['gacha_types'][0])
                ->setGachaName($bannerGroup['name'])
                ->setItemType($itemType)
                ->setItemId($itemId)
                ->setName($name)
                ->setRarity($rarityConfig->getS()->getRarity())
                ->setPulledAt($pulledAt)
                ->setOrigin(PullOrigin::Manual)
                ->setDeclaredPity($request->pity)
                ->setRawPayload(['source' => 'manual']);
            $this->entityManager->persist($pull);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $request->banner);
            $this->entityManager->flush();

            return $pull;
        } finally {
            $lock->release();
        }
    }

    /**
     * Operation A: declares the real pity of an S (null clears it), then realigns the banner. On a
     * recorded S, a pity equal to the recorded one is stored as null (nothing is missing).
     *
     * @throws PullProfileLockedException   when another pull operation runs for the profile
     * @throws ManualPullViolationException when a shared rule is broken (422)
     * @throws NotFoundHttpException        when the pull belongs to another profile
     * @throws BadRequestHttpException      when the pull is not an S of a configured banner
     */
    public function declarePity(HoyoverseGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, HoyoversePull $pull, ?int $pity): void
    {
        $this->assertPullOfProfile($profile, $pull);
        $bannerKey = $this->getBannerKeyOf($gameService, $pull);
        $rarityConfig = $gameService->getRarityConfig();
        if ($pull->getOrigin() === PullOrigin::Filler || !$this->statsCalculator->isS(['rarity' => $pull->getRarity()], $rarityConfig)) {
            throw new BadRequestHttpException('Only the pity of an S pull can be declared.');
        }
        $bannerGroup = $gameService->getGachaConfig()->getBannerGroups()[$bannerKey];

        $lock = $this->acquireLock($profile);
        try {
            $entries = $this->statsCalculator->toTimelineEntries($this->pullRepository->findBannerRows($profile, $bannerGroup['gacha_types']), $rarityConfig);
            $pullId = (int) $pull->getId();
            $this->guard->assertCanDeclare($entries, $pullId, $pity, $bannerGroup['s_max_pity']);

            if ($pity !== null && $pull->getOrigin() === PullOrigin::Recorded) {
                // describe() reads the windows only: "now" does not matter here
                $window = $this->planner->describe($entries, $this->timezoneResolver->getServerWallClockNow($profile))['windows'][$pullId];
                if ($pity === $window->recordedPity) {
                    $pity = null;
                }
            }

            $pull->setDeclaredPity($pity);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $bannerKey);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    /**
     * Deletes a manual S, then realigns the banner (its filler rows go with it).
     *
     * @throws PullProfileLockedException when another pull operation runs for the profile
     * @throws NotFoundHttpException      when the pull belongs to another profile
     * @throws BadRequestHttpException    when the pull is not a manual row
     */
    public function deleteManual(HoyoverseGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, HoyoversePull $pull): void
    {
        $this->assertPullOfProfile($profile, $pull);
        if ($pull->getOrigin() !== PullOrigin::Manual) {
            throw new BadRequestHttpException('Only a manual pull can be deleted.');
        }
        $bannerKey = $this->getBannerKeyOf($gameService, $pull);

        $lock = $this->acquireLock($profile);
        try {
            $this->entityManager->remove($pull);
            $this->entityManager->flush();

            $this->realign($profile, $gameService, $bannerKey);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    /**
     * Brings one configured banner group to its expected state: manual S rows merged into the recorded
     * S that arrived later, and the filler rows each declared pity needs. Idempotent.
     *
     * The caller must hold the profile lock (PullBackupImportService::getProfileLockKey()), have flushed
     * its own changes, and flush after this call: the changes are only scheduled on the entity manager.
     *
     * @param string $bannerKey Banner group key, or one of its gacha types (GachaConfig::getBanner())
     *
     * @return FillerPlan The applied plan (empty for a banner missing from the configuration)
     */
    public function realign(HoyoverseGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, string $bannerKey): FillerPlan
    {
        $banner = $gameService->getGachaConfig()->getBanner($bannerKey);
        if ($banner === null) {
            return new FillerPlan();
        }
        $bannerKey = $banner->getKey();
        $bannerGroup = $banner->toGroupArray();

        $rows = $this->pullRepository->findBannerRows($profile, $bannerGroup['gacha_types']);
        $plan = $this->planner->plan($this->statsCalculator->toTimelineEntries($rows, $gameService->getRarityConfig()));
        foreach ($plan->warnings as $warning) {
            $this->logger?->warning(\sprintf('Realign of banner %s for profile #%d: %s', $bannerKey, (int) $profile->getId(), $warning));
        }
        if (!$plan->isEmpty()) {
            $this->applyPlan($profile, $bannerGroup, $plan);
        }

        return $plan;
    }

    /**
     * Realigns every configured banner group of the profile. Same contract as realign().
     */
    public function realignAll(HoyoverseGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService): void
    {
        foreach (array_keys($gameService->getGachaConfig()->getBannerGroups()) as $bannerKey) {
            $this->realign($profile, $gameService, (string) $bannerKey);
        }
    }

    /**
     * @param array{key: string, name: string, gacha_types: list<string>} $bannerGroup
     */
    private function applyPlan(HoyoverseGameProfile $profile, array $bannerGroup, FillerPlan $plan): void
    {
        $ids = array_values(array_unique([
            ...array_keys($plan->declaredPityUpdates),
            ...array_keys($plan->merges),
            ...array_values($plan->merges),
            ...array_keys($plan->fillerMoves),
            ...array_keys($plan->fillerPullNumbers),
            ...$plan->fillerDeletes,
        ]));
        $pulls = [];
        if ($ids !== []) {
            foreach ($this->pullRepository->findBy(['gameProfile' => $profile, 'id' => $ids]) as $pull) {
                $pulls[(int) $pull->getId()] = $pull;
            }
        }

        // Order of FillerPlan: declared pities, merged manual rows, then filler moves, deletes and creates
        foreach ($plan->declaredPityUpdates as $id => $declaredPity) {
            $pulls[$id]?->setDeclaredPity($declaredPity);
        }
        foreach ($plan->merges as $id => $recordedId) {
            if (!isset($pulls[$id])) {
                continue;
            }
            // The recorded S inherits the rate-up flag of the manual S unless its own source gave one
            if (isset($pulls[$recordedId]) && $pulls[$recordedId]->isUp() === null) {
                $pulls[$recordedId]->setIsUp($pulls[$id]->isUp());
            }
            $this->entityManager->remove($pulls[$id]);
        }
        foreach ($plan->fillerMoves as $id => $pulledAt) {
            $pulls[$id]?->setPulledAt($pulledAt);
        }
        foreach ($plan->fillerPullNumbers as $id => $pullNumber) {
            $pulls[$id]?->setPullNumber($pullNumber);
        }
        foreach ($plan->fillerDeletes as $id) {
            if (isset($pulls[$id])) {
                $this->entityManager->remove($pulls[$id]);
            }
        }

        if ($plan->fillerCreates !== []) {
            // The profile may be detached (backup import clears the entity manager)
            /** @var HoyoverseGameProfile $profileRef */
            $profileRef = $this->entityManager->getReference(HoyoverseGameProfile::class, (int) $profile->getId());
            foreach ($plan->fillerCreates as $slot) {
                $this->entityManager->persist($this->createFiller($profileRef, $bannerGroup, $slot->pulledAt)->setPullNumber($slot->pullNumber));
            }
        }
    }

    /**
     * @param array{name: string, gacha_types: list<string>} $bannerGroup
     */
    private function createFiller(HoyoverseGameProfile $profile, array $bannerGroup, \DateTimeImmutable $pulledAt): HoyoversePull
    {
        return (new HoyoversePull())
            ->setGameProfile($profile)
            ->setPullId(ManualPull::FILLER_ID_PREFIX.Uuid::v7()->toRfc4122())
            ->setGachaType((string) $bannerGroup['gacha_types'][0])
            ->setGachaName($bannerGroup['name'])
            ->setItemType(self::FILLER_ITEM_TYPE)
            ->setItemId(null)
            ->setName(ManualPull::FILLER_NAME)
            ->setRarity(ManualPull::FILLER_RARITY)
            ->setPulledAt($pulledAt)
            ->setOrigin(PullOrigin::Filler)
            ->setRawPayload(null);
    }

    /**
     * Item type of a manual S: the requested one, else the one of the matching suggestion. Free text
     * without a type is rejected, since the type drives the icon and the item's category.
     *
     * @throws ManualPullViolationException
     */
    private function resolveItemType(GameInterface&HasPullTrackerInterface $gameService, ?string $requested, ?string $itemId, string $name): string
    {
        if ($requested !== null && trim($requested) !== '') {
            return trim($requested);
        }

        $suggestion = $this->suggestionProvider->findSuggestion($gameService, $itemId, $name);
        if ($suggestion !== null && $suggestion['item_type'] !== '') {
            return $suggestion['item_type'];
        }

        throw new ManualPullViolationException(ManualPullViolationException::ITEM_TYPE_REQUIRED, 'Choose the item type of a pull that is not in the suggestions.');
    }

    private function assertPullOfProfile(HoyoverseGameProfile $profile, HoyoversePull $pull): void
    {
        if ($pull->getGameProfile()?->getId() !== $profile->getId()) {
            throw new NotFoundHttpException('Pull not found.');
        }
    }

    /**
     * Key of the configured banner group of a pull (GachaConfig::getBanner() matches its gacha type).
     */
    private function getBannerKeyOf(GameInterface&HasPullTrackerInterface $gameService, HoyoversePull $pull): string
    {
        $banner = $gameService->getGachaConfig()->getBanner((string) $pull->getGachaType())
            ?? throw new BadRequestHttpException('The pull does not belong to a configured banner.');

        return $banner->getKey();
    }

    private function acquireLock(HoyoverseGameProfile $profile): LockInterface
    {
        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey((int) $profile->getId()), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire(false)) {
            throw new PullProfileLockedException(\sprintf('Another pull operation is running for profile #%d: try again in a moment.', (int) $profile->getId()));
        }

        return $lock;
    }
}
