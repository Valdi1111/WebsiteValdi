<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\Pull\ManualPullService;
use App\GryphlineBundle\Service\Pull\PullBackupImportService;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use App\GryphlineBundle\Service\Pull\PullSyntheticReconcilerManager;
use App\GryphlineBundle\Service\SkportAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * @implements AbstractProfileTaskMessageHandler<SyncPullsPageProfileMessage>
 */
#[AsMessageHandler]
class SyncPullsPageProfileHandler extends AbstractProfileTaskMessageHandler
{
    /**
     * Dynamic jitter delay boundaries (in milliseconds) between consecutive page requests.
     */
    private const int DELAY_MIN_MS = 1500;
    private const int DELAY_MAX_MS = 2500;
    private const float LOCK_TTL_SECONDS = 60.0;

    public function __construct(
        GryphlineGameProfileRepository $gameProfileRepository,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        ServiceCollectionInterface $locatorByGameBiz,
        #[Target('gryphline')]
        LoggerInterface $logger,
        UnifiedNotificationService $notificationService,
        private readonly SkportAuthService $authService,
        private readonly GryphlinePullRepository $pullRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly PullSyntheticReconcilerManager $syntheticReconcilerManager,
        private readonly PullOperationTracker $operationTracker,
        private readonly LockFactory $lockFactory,
        private readonly ManualPullService $manualPullService,
    ) {
        parent::__construct(
            $gameProfileRepository,
            $locatorByGameBiz,
            $logger,
            $notificationService
        );
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
        if (!$gameService instanceof HasAutoPullImportInterface) {
            return;
        }

        if (!$message instanceof SyncPullsPageProfileMessage) {
            return;
        }

        $profile = $accountData->getGameProfile();
        $profileId = (int) $profile->getId();
        $category = $message->getCategory();
        $operationId = $message->getOperationId();
        $bannerTypes = $gameService->getGachaConfig()->getBannerTypes();
        $bannerName = $bannerTypes[$category] ?? ('Banner ' . $category);

        // Acquire per-profile lock to prevent overlapping backup imports or concurrent pull syncs
        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey($profileId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire(false)) {
            $this->bus->dispatch($message, [new DelayStamp(2000)]);

            return;
        }

        try {
            $account = $profile->getAccount();
            $u8Token = $message->getU8Token();

            // 1. Resolve or generate u8_token on the first page if not already carried in the message
            if ($u8Token === null || $u8Token === '') {
                $u8Token = $this->authService->getEndfieldU8Token($account, $profile->getGameUid());
            }

            // 2. Fetch gacha pulls for the current page from the upstream API
            $nextSeqId = null;
            $pulls = $gameService->fetchPullsPage(
                $profile,
                $category,
                $u8Token,
                $message->getSeqId(),
                $nextSeqId
            );

            if (empty($pulls) && ($nextSeqId === null || $nextSeqId === '')) {
                // Last page of the banner: earlier pages (separate messages) may have stored new pulls
                $this->realignBanner($profile, $gameService, $category);

                if ($operationId !== null) {
                    $this->operationTracker->recordSyncBannerPage($operationId, $category, 0, 0, true, $bannerName);
                }

                return;
            }

            // 3. Persist new pulls, reconcile synthetic backup pulls, and stop on first existing official pull
            $pageHasExistingPull = false;
            $newCount = 0;
            $promotedCount = 0;

            foreach ($pulls as $pull) {
                if ($this->pullRepository->existsByProfileAndSeqId($profile, (string) $pull->getSeqId(), $pull->getPoolCategory())) {
                    $pageHasExistingPull = true;
                    break;
                }

                // Delegate synthetic pull promotion to registered backup reconcilers (if any)
                $promoted = $this->syntheticReconcilerManager->reconcile($profile, $pull, $gameService);
                if ($promoted !== null) {
                    $promotedCount++;
                    continue;
                }

                $this->entityManager->persist($pull);
                $newCount++;
            }

            if ($newCount > 0 || $promotedCount > 0) {
                $this->entityManager->flush();

                $this->logger->info(sprintf(
                    'Recorded %d new and promoted %d synthetic pull(s) for %s (Category %s, seq_id %s).',
                    $newCount,
                    $promotedCount,
                    $profile->getNickname(),
                    $category,
                    $message->getSeqId() ?? 'initial'
                ));
            }

            // 4. Continue pagination if no existing record was hit and the upstream API indicates more records exist
            $hasMorePages = !$pageHasExistingPull && $nextSeqId !== null && $nextSeqId !== '';

            if (!$hasMorePages) {
                $this->realignBanner($profile, $gameService, $category);
            }

            if ($operationId !== null) {
                $this->operationTracker->recordSyncBannerPage(
                    $operationId,
                    $category,
                    $newCount,
                    $promotedCount,
                    !$hasMorePages,
                    $bannerName
                );
            }

            if ($hasMorePages) {
                $nextPageDelayMs = random_int(self::DELAY_MIN_MS, self::DELAY_MAX_MS);

                $this->bus->dispatch(
                    new SyncPullsPageProfileMessage(
                        $profile->getId(),
                        $category,
                        $nextSeqId,
                        $u8Token,
                        $operationId
                    ),
                    [new DelayStamp($nextPageDelayMs)]
                );
            }
        } catch (\Throwable $e) {
            if ($operationId !== null) {
                $this->operationTracker->failSyncBanner($operationId, $category, $e->getMessage(), $bannerName);
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Realigns the manual pulls and fillers of the synced banner (the profile lock is held by the caller).
     */
    private function realignBanner(GryphlineGameProfile $profile, HasAutoPullImportInterface $gameService, string $category): void
    {
        if (!$gameService instanceof HasManualPullsInterface) {
            return;
        }

        $bannerKey = $gameService->getPoolTypeForCategory($category);
        if (!isset($gameService->getGachaConfig()->getBannerGroups('categories')[$bannerKey])) {
            return;
        }

        $this->manualPullService->realign($profile, $gameService, $bannerKey);
    }
}
