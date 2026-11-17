<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\SkportAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
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
            $message->getCategory(),
            $u8Token,
            $message->getSeqId(),
            $nextSeqId
        );

        if (empty($pulls) && ($nextSeqId === null || $nextSeqId === '')) {
            return;
        }

        // 3. Persist new pulls and check if we encountered an already stored record (idempotent stop)
        $pageHasExistingPull = false;
        $newCount = 0;

        foreach ($pulls as $pull) {
            if ($this->pullRepository->existsByProfileAndSeqId($profile, (string) $pull->getSeqId(), $pull->getPoolCategory())) {
                $pageHasExistingPull = true;
                break;
            }

            $this->entityManager->persist($pull);
            $newCount++;
        }

        if ($newCount > 0) {
            $this->entityManager->flush();

            $this->logger->info(sprintf(
                'Recorded %d new pull(s) for %s (Category %s, seq_id %s).',
                $newCount,
                $profile->getNickname(),
                $message->getCategory(),
                $message->getSeqId() ?? 'initial'
            ));
        }

        // 4. Continue pagination if no existing record was hit and the upstream API indicates more records exist
        if (!$pageHasExistingPull && $nextSeqId !== null && $nextSeqId !== '') {
            $nextPageDelayMs = random_int(self::DELAY_MIN_MS, self::DELAY_MAX_MS);

            $this->bus->dispatch(
                new SyncPullsPageProfileMessage(
                    $profile->getId(),
                    $message->getCategory(),
                    $nextSeqId,
                    $u8Token
                ),
                [new DelayStamp($nextPageDelayMs)]
            );
        }
    }
}
