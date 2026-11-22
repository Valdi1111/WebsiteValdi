<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\PullBackupImportService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\PullSyntheticReconcilerManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<SyncPullsPageProfileMessage>
 */
#[AsMessageHandler]
class SyncPullsPageProfileHandler extends AbstractProfileTaskMessageHandler
{
    /**
     * Maximum number of records requested per gacha API call.
     */
    private const int PAGE_SIZE = 20;

    /**
     * Dynamic jitter delay boundaries (in milliseconds) between consecutive page requests.
     */
    private const int DELAY_MIN_MS = 1500;
    private const int DELAY_MAX_MS = 2500;

    private const float LOCK_TTL_SECONDS = 60.0;

    private EntityManagerInterface $entityManager;
    private HoyoversePullRepository $pullRepository;
    private MessageBusInterface $bus;
    private PullSyntheticReconcilerManager $syntheticReconcilerManager;
    private PullOperationTracker $operationTracker;
    private LockFactory $lockFactory;
    private ManualPullService $manualPullService;

    #[Required]
    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    #[Required]
    public function setPullRepository(HoyoversePullRepository $pullRepository): void
    {
        $this->pullRepository = $pullRepository;
    }

    #[Required]
    public function setBus(MessageBusInterface $bus): void
    {
        $this->bus = $bus;
    }

    #[Required]
    public function setSyntheticReconcilerManager(PullSyntheticReconcilerManager $syntheticReconcilerManager): void
    {
        $this->syntheticReconcilerManager = $syntheticReconcilerManager;
    }

    #[Required]
    public function setOperationTracker(PullOperationTracker $operationTracker): void
    {
        $this->operationTracker = $operationTracker;
    }

    #[Required]
    public function setLockFactory(LockFactory $lockFactory): void
    {
        $this->lockFactory = $lockFactory;
    }

    #[Required]
    public function setManualPullService(ManualPullService $manualPullService): void
    {
        $this->manualPullService = $manualPullService;
    }

    public function __invoke(SyncPullsPageProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasPullTrackerInterface) {
            return;
        }

        if (!$message instanceof SyncPullsPageProfileMessage) {
            return;
        }

        $profile = $accountData->getGameProfile();
        $gachaType = $message->getGachaType();
        $operationId = $message->getOperationId();
        $bannerName = $gameService->getGachaConfig()->getBanner($gachaType)?->getName();

        $lock = $this->lockFactory->createLock(
            PullBackupImportService::getProfileLockKey((int) $profile->getId()),
            self::LOCK_TTL_SECONDS
        );
        $lock->acquire(true);

        try {
            $authkey = $message->getAuthkey();
            $extraQueryParams = $message->getExtraQueryParams();

            // 1. Resolve or generate authkey on the first page if not already carried in the message
            if (($authkey === null || $authkey === '') && $gameService instanceof HasStokenPullImportInterface) {
                try {
                    $authkey = $this->cookieUtils->generateAuthKey($profile, $gameService->getAuthkeyAppId());
                } catch (\Throwable $e) {
                    if ($operationId !== null) {
                        $this->operationTracker->failSyncBanner($operationId, $gachaType, $e->getMessage(), $bannerName);
                    }
                    throw $e;
                }
            }

            if ($authkey === null || $authkey === '') {
                $reason = 'No authkey available and game does not support SToken import.';
                $this->logger->warning(sprintf(
                    'Cannot sync pulls page for %s (Profile %d, Banner %s): %s',
                    $profile->getNickname(),
                    $profile->getId(),
                    $gachaType,
                    $reason
                ));
                if ($operationId !== null) {
                    $this->operationTracker->failSyncBanner($operationId, $gachaType, $reason, $bannerName);
                }

                return;
            }

            // 2. Fetch gacha pulls for the current page from the upstream API
            try {
                $pulls = $gameService->fetchPullsPage(
                    $profile,
                    $gachaType,
                    $authkey,
                    $message->getEndId(),
                    $extraQueryParams,
                    self::PAGE_SIZE
                );
            } catch (\Throwable $e) {
                if ($operationId !== null) {
                    $this->operationTracker->failSyncBanner($operationId, $gachaType, $e->getMessage(), $bannerName);
                }
                throw $e;
            }

            if (empty($pulls)) {
                $this->realignBanner($profile, $gameService, $gachaType);
                if ($operationId !== null) {
                    $this->operationTracker->recordSyncBannerPage($operationId, $gachaType, 0, 0, true, $bannerName);
                }

                return;
            }

            // 3. Persist new pulls, promote matching synthetic backup pulls, or stop on an existing real pull
            $pageHasExistingPull = false;
            $newCount = 0;
            $promotedCount = 0;

            foreach ($pulls as $pull) {
                if ($this->pullRepository->existsByProfileAndPullId($profile, (string) $pull->getPullId())) {
                    $pageHasExistingPull = true;
                    break;
                }

                if ($this->syntheticReconcilerManager->reconcile($profile, $pull, $gameService) !== null) {
                    $promotedCount++;
                    continue;
                }

                $this->entityManager->persist($pull);
                $newCount++;
            }

            // 4. Continue pagination if no existing real record was hit and the current page reached full capacity.
            // The next page is queued before this one is committed: a retry of a committed page stops on its own
            // (already stored) pulls, so a failure after the flush must not be able to drop the continuation.
            // If the flush fails instead, the retry queues the next page again and that duplicate stops on its first pull.
            $hasMorePages = !$pageHasExistingPull && count($pulls) >= self::PAGE_SIZE;

            if ($hasMorePages) {
                $lastPull = end($pulls);
                $nextEndId = (string) $lastPull->getPullId();
                $nextPageDelayMs = random_int(self::DELAY_MIN_MS, self::DELAY_MAX_MS);

                $this->bus->dispatch(
                    new SyncPullsPageProfileMessage(
                        $profile->getId(),
                        $gachaType,
                        $nextEndId,
                        $authkey,
                        $extraQueryParams,
                        $operationId
                    ),
                    [new DelayStamp($nextPageDelayMs)]
                );
            }

            // 5. Commit the page, then realign the banner on its last page
            $this->entityManager->flush();

            if ($newCount > 0 || $promotedCount > 0) {
                $this->logger->info(sprintf(
                    'Processed pulls for %s (Banner %s, end_id %s): %d new, %d promoted.',
                    $profile->getNickname(),
                    $gachaType,
                    $message->getEndId(),
                    $newCount,
                    $promotedCount
                ));
            }

            if (!$hasMorePages) {
                $this->realignBanner($profile, $gameService, $gachaType);
            }

            if ($operationId !== null) {
                $this->operationTracker->recordSyncBannerPage(
                    $operationId,
                    $gachaType,
                    $newCount,
                    $promotedCount,
                    !$hasMorePages,
                    $bannerName
                );
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Last page of a banner: the synced pulls may complete or move the windows of declared pities.
     * Runs under the profile lock held by processProfile().
     */
    private function realignBanner(HoyoverseGameProfile $profile, GameInterface&HasPullTrackerInterface $gameService, string $gachaType): void
    {
        // The message carries the banner key (realign() also accepts a member gacha type)
        if (!$this->manualPullService->realign($profile, $gameService, $gachaType)->isEmpty()) {
            $this->entityManager->flush();
        }
    }
}
